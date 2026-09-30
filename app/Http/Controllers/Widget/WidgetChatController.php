<?php

namespace App\Http\Controllers\Widget;

use App\Enums\AnswerPhase;
use App\Enums\ChatRole;
use App\Enums\DocumentStatus;
use App\Enums\MessageStatus;
use App\Enums\NotificationType;
use App\Enums\SupportMessageRole;
use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Models\SupportConversation;
use App\Models\SupportMessage;
use App\Models\WidgetConversation;
use App\Models\WidgetConversationAuditLog;
use App\Models\WidgetEvent;
use App\Models\WidgetMessage;
use App\Services\Ai\ChatClient;
use App\Services\Notifier;
use App\Services\RAG\ContextExpander;
use App\Services\RAG\EmbeddingClient;
use App\Services\RAG\IntentClassifier;
use App\Services\RAG\KeywordSearch;
use App\Services\RAG\PromptBuilder;
use App\Services\RAG\RankFusion;
use App\Services\RAG\SimilaritySearch;
use App\Services\RAG\VectorStore;
use App\Services\Support\HandoffDetector;
use App\Services\Support\SupportInbox;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Public, session-less chat API for the embedded widget.
 *
 * A visitor is identified only by a random id their browser keeps in
 * localStorage — no IP, no fingerprint, nothing personal. Access is granted by
 * the site's public key and bounded by that site's own message quota, and every
 * retrieval query is scoped through the documents the owner linked to the site,
 * so one tenant can never see another's content.
 */
class WidgetChatController extends Controller
{
    /**
     * What the assistant says when a visitor asks for a person. Streamed back
     * verbatim so the bubble matches the support transcript that opens with it.
     */
    private const string HANDOFF_TEXT = "You're connected with the DocuMind support team. Describe what you need a hand with and a specialist will reply right here.";

    public function __construct(
        private readonly EmbeddingClient $embeddings,
        private readonly VectorStore $store,
        private readonly SimilaritySearch $similarity,
        private readonly KeywordSearch $keywords,
        private readonly RankFusion $fusion,
        private readonly ContextExpander $expander,
        private readonly PromptBuilder $prompts,
        private readonly ChatClient $chatClient,
        private readonly IntentClassifier $intents,
        private readonly HandoffDetector $handoffs,
        private readonly SupportInbox $support,
    ) {}

    /**
     * Open a visitor conversation and hand back the widget configuration.
     */
    public function store(Request $request, string $siteKey): JsonResponse
    {
        $site = $this->site($request, $siteKey);

        $visitor = $request->validate([
            'visitor_id' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]{8,64}$/'],
        ], [
            'visitor_id.required' => 'A visitor id is required.',
            'visitor_id.regex' => 'That visitor id is not valid.',
        ]);

        $visitorId = $visitor['visitor_id'];
        $visitorIdHash = hash_hmac('sha256', $visitorId, (string) config('app.key'));
        $existing = WidgetConversation::query()
            ->where('site_id', $site->getKey())
            ->where('visitor_id_hash', $visitorIdHash)
            ->latest('id')
            ->first();
        $needsEmailConsent = $site->collect_email && $existing?->visitor_email_consent_at === null;

        $validated = $request->validate([
            'email' => [Rule::requiredIf($needsEmailConsent), 'nullable', 'email:rfc', 'max:190'],
            'email_consent' => [$needsEmailConsent ? 'required' : 'nullable', 'boolean'],
        ], [
            'email.required' => 'Enter your email address to start this chat.',
            'email.email' => 'Enter a valid email address, such as name@example.com.',
            'email_consent.required' => 'Confirm consent to continue.',
        ]);

        if ($needsEmailConsent && ! $validated['email_consent']) {
            throw ValidationException::withMessages([
                'email_consent' => 'Confirm consent to continue.',
            ]);
        }

        $email = $needsEmailConsent ? mb_strtolower(trim($validated['email'])) : null;

        $capturedConsent = false;
        $conversation = DB::transaction(function () use ($email, $needsEmailConsent, $site, $visitorId, &$capturedConsent): WidgetConversation {
            $conversation = WidgetConversation::query()
                ->where('site_id', $site->getKey())
                ->where('visitor_id_hash', hash_hmac('sha256', $visitorId, (string) config('app.key')))
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if ($conversation === null) {
                $conversation = WidgetConversation::create([
                    'site_id' => $site->getKey(),
                    'workspace_id' => $site->workspace_id,
                    'visitor_id' => $visitorId,
                    'visitor_email' => $email,
                    'visitor_email_hash' => $email === null ? null : hash_hmac('sha256', $email, (string) config('app.key')),
                    'visitor_email_consent_at' => $needsEmailConsent ? now() : null,
                    'classification' => $needsEmailConsent ? 'lead' : 'support',
                    'follow_up_status' => $needsEmailConsent ? 'pending' : 'none',
                ]);
                $capturedConsent = $needsEmailConsent;
            } elseif ($needsEmailConsent && $conversation->visitor_email_consent_at === null) {
                $conversation->forceFill([
                    'visitor_email' => $email,
                    'visitor_email_hash' => hash_hmac('sha256', (string) $email, (string) config('app.key')),
                    'visitor_email_consent_at' => now(),
                    'classification' => 'lead',
                    'follow_up_status' => 'pending',
                ])->save();
                $capturedConsent = true;
            }

            if ($capturedConsent) {
                WidgetConversationAuditLog::query()->create([
                    'workspace_id' => $site->workspace_id,
                    'site_id' => $site->getKey(),
                    'conversation_id' => $conversation->getKey(),
                    'action' => 'visitor.email_consent_recorded',
                    'details' => ['consent_recorded' => true],
                    'created_at' => now(),
                ]);
            }

            return $conversation;
        });

        $site->rotateQuotaIfNeeded();
        $remaining = $site->remainingQuota();

        if ($capturedConsent) {
            $this->siteNotifier($site)
                ->type(NotificationType::LeadCaptured)
                ->title('New lead captured')
                ->body('A visitor shared their email on '.$site->name.'.')
                ->link(route('widget.leads.index', $site), 'Open inbox')
                ->dedupe('lead.captured.'.$conversation->getKey())
                ->send();
        } elseif ($conversation->wasRecentlyCreated) {
            Notifier::make()
                ->type(NotificationType::ConversationCreated)
                ->to($site->user)
                ->title('New widget conversation')
                ->body('A visitor opened a chat on '.$site->name.'.')
                ->link(route('widget.leads.index', $site), 'Open inbox')
                ->workspace($site->workspace_id)
                ->dedupe('conversation.created.'.$conversation->getKey())
                ->send();
        }

        return response()->json([
            'conversation_id' => $conversation->getKey(),
            'session_id' => $conversation->visitor_id,
            'email_captured' => $conversation->visitor_email_consent_at !== null,
            'reused_conversation' => $existing !== null,
            'config' => $site->widgetConfig(),
            'quota' => [
                'remaining' => $remaining,
                'exhausted' => $remaining === 0,
            ],
        ]);
    }

    /**
     * Refresh public appearance settings without opening a conversation or spending quota.
     */
    public function config(Request $request, string $siteKey): JsonResponse
    {
        $site = $this->site($request, $siteKey);

        return response()->json(['config' => $site->widgetConfig()])
            ->header('Cache-Control', 'no-store, private');
    }

    /**
     * Replay a visitor's transcript when they reopen the bubble.
     */
    public function show(Request $request, string $siteKey, WidgetConversation $conversation): JsonResponse
    {
        $site = $this->site($request, $siteKey);
        $this->authorizeConversation($request, $site, $conversation);
        $site->rotateQuotaIfNeeded();
        $remaining = $site->remainingQuota();

        $support = $this->latestSupport($conversation);

        return response()->json([
            'config' => $site->widgetConfig(),
            'email_captured' => $conversation->visitor_email_consent_at !== null,
            'session_id' => $conversation->visitor_id,
            'support' => $support === null ? null : $this->supportPayload($support),
            'support_messages' => $this->supportMessages($support, 0),
            'messages' => $conversation->messages()
                ->orderBy('id')
                ->get(['id', 'role', 'content', 'created_at', 'sources', 'was_refused', 'was_helpful'])
                ->map(function (WidgetMessage $message): array {
                    return [
                        'id' => $message->id,
                        'role' => $message->role->value,
                        'content' => $message->content,
                        'created_at' => $message->created_at->toIso8601String(),
                        'sources' => $message->sourceBadges() === [] ? [] : [['knowledge_used' => true]],
                        'refused' => $message->was_refused,
                        'feedback' => $message->was_helpful,
                    ];
                }),
            'quota' => [
                'remaining' => $remaining,
                'exhausted' => $remaining === 0,
            ],
        ]);
    }

    /**
     * Lightweight poll for agent replies while the visitor is in support mode.
     * Never spends quota — human support keeps working after the site's
     * visitor-message budget is gone.
     */
    public function support(Request $request, string $siteKey, WidgetConversation $conversation): JsonResponse
    {
        $site = $this->site($request, $siteKey);
        $this->authorizeConversation($request, $site, $conversation);

        $validated = $request->validate([
            'since' => ['nullable', 'integer', 'min:0'],
        ]);

        $support = $this->latestSupport($conversation);

        return response()->json([
            'support' => $support === null ? null : $this->supportPayload($support),
            'messages' => $this->supportMessages($support, (int) ($validated['since'] ?? 0)),
        ])->header('Cache-Control', 'no-store, private');
    }

    /**
     * Answer a visitor question, streaming the reply as Server-Sent Events.
     */
    public function messages(Request $request, string $siteKey, WidgetConversation $conversation): Response
    {
        $site = $this->site($request, $siteKey);
        $this->authorizeConversation($request, $site, $conversation);

        $validated = $request->validate([
            'message' => ['required', 'string', 'max:1000'],
        ], [
            'message.required' => 'Type a message first.',
            'message.max' => 'Please keep your message under 1,000 characters.',
        ]);

        $question = trim($validated['message']);

        if ($question === '') {
            return response()->json(['message' => 'Type a message first.'], 422);
        }

        // A live human conversation outranks the assistant: follow-ups land
        // in the support transcript and never spend quota, so help keeps
        // working after the site's visitor-message budget is gone.
        $ticket = $this->latestSupport($conversation);

        if ($ticket !== null && $ticket->isLive()) {
            return $this->supportReply($site, $conversation, $ticket, $question);
        }

        // Asking for a person opens the support transcript instead of an
        // answer — decided before the consent gate and quota, because human
        // support must never be blocked by either.
        if ($this->handoffs->wantsHuman($question)) {
            return $this->handoff($site, $conversation, $question);
        }

        abort_if(
            $site->collect_email && $conversation->visitor_email_consent_at === null,
            403,
            'Provide your email and consent before starting this chat.',
        );

        $site->rotateQuotaIfNeeded();

        if (! $site->consumeQuota()) {
            $this->siteNotifier($site)
                ->type(NotificationType::WidgetQuotaReached)
                ->title('Message quota reached')
                ->body($site->name.' used its monthly visitor message quota. New chats are paused until the next period.')
                ->link(route('widget.show', $site), 'Open site')
                ->dedupe('site.'.$site->getKey().'.quota.'.($site->quota_started_at?->format('Y-m')))
                ->send();

            return response()->json([
                'message' => 'This assistant has reached its monthly limit. Ask to talk to a human to reach our support team.',
                'exhausted' => true,
            ], 429);
        }

        $startedAt = microtime(true);

        $asked = $conversation->messages()->create([
            'site_id' => $site->getKey(),
            'role' => ChatRole::User,
            'content' => $question,
            'credit_cost' => 1,
        ]);

        // The first message of a conversation is already covered by
        // ConversationCreated (raised when the session opened); anything after
        // that is a visitor coming back and deserves its own alert. Decided
        // here, before `message_count` is incremented, and sent only once the
        // answer is done so the owner's alert never delays the visitor.
        $isFollowUp = $conversation->message_count > 0;

        $assistant = $conversation->messages()->create([
            'site_id' => $site->getKey(),
            'role' => ChatRole::Assistant,
            'content' => '',
            'status' => MessageStatus::Streaming,
            'credit_cost' => 1,
        ]);

        $conversation->increment('message_count', 2);

        return response()->stream(function () use ($site, $conversation, $question, $asked, $assistant, $startedAt, $isFollowUp): void {
            // Same reasoning as the main chat: a dropped socket must still leave
            // a terminal assistant row instead of an empty message forever.
            ignore_user_abort(true);

            $emit = static function (string $event, array $data): void {
                echo 'event: '.$event."\n";
                echo 'data: '.json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n\n";

                if (ob_get_level() > 0) {
                    @ob_flush();
                }

                flush();
            };

            try {
                // First byte of the stream: flushes the headers so the widget can
                // show a real status instead of waiting on retrieval and the model.
                $onStatus = static function (AnswerPhase $phase) use ($emit): void {
                    $emit('status', ['phase' => $phase->value]);
                };

                $onStatus(AnswerPhase::Reading);

                $result = $this->answer(
                    $site,
                    $conversation,
                    $asked,
                    $question,
                    static function (string $delta) use ($emit): void {
                        $emit('delta', ['text' => $delta]);
                    },
                    $startedAt,
                    $onStatus,
                );

                // An empty terminal answer counts as a failure so it shows up in
                // the owner's outcome metrics instead of silently looking fine.
                $empty = trim($result['content']) === '';

                $assistant->update([
                    'content' => $result['content'],
                    'sources' => $result['sources'],
                    'model_used' => $result['model'],
                    'latency_ms' => $result['latency_ms'],
                    'was_refused' => $result['refused'],
                    'was_fallback' => $result['fallback'],
                    'status' => $empty ? MessageStatus::Failed : MessageStatus::Complete,
                    'error_reason' => $empty ? 'empty_response' : null,
                ]);

                if ($empty) {
                    $this->escalate($conversation, $site, 'empty_response');
                }

                // A refusal consumed no real work, so it must not cost the
                // owner a quota unit — the main chat already refunds in this case.
                if ($result['refused']) {
                    $assistant->forceFill(['credit_cost' => 0])->save();
                    $site->releaseQuota();
                }

                $emit('sources', [
                    'sources' => $result['sources'] === [] ? [] : [['knowledge_used' => true]],
                ]);
                $emit('done', ['id' => $assistant->getKey()]);
            } catch (Throwable $exception) {
                $reason = self::classifyFailure($exception);

                $assistant->update([
                    'content' => 'Something went wrong. Please try again.',
                    'status' => MessageStatus::Failed,
                    'error_reason' => $reason,
                ]);
                $site->releaseQuota();
                $this->escalate($conversation, $site, $reason);

                report($exception);

                $emit('error', ['message' => 'Something went wrong. Please try again.']);
            } finally {
                if ($isFollowUp) {
                    $this->notifyOwnerOfReply($site, $conversation, $question, $asked);
                }
            }
        }, 200, [
            'Content-Type' => 'text/event-stream; charset=utf-8',
            'Cache-Control' => 'no-cache, no-transform',
            'X-Accel-Buffering' => 'no',
            'Connection' => 'keep-alive',
        ]);
    }

    /**
     * Tell the owner a returning visitor spoke, after the answer has streamed.
     *
     * @param  WidgetMessage  $asked  the visitor's message that triggered the reply
     */
    private function notifyOwnerOfReply(Site $site, WidgetConversation $conversation, string $question, WidgetMessage $asked): void
    {
        $this->siteNotifier($site)
            ->type(NotificationType::ConversationReplied)
            ->title('New visitor message')
            ->body(Str::limit(trim($question), 160).' — '.$site->name)
            ->link(route('widget.leads.index', $site), 'Open inbox')
            ->group('conversation.'.$conversation->getKey())
            ->dedupe('conversation.replied.'.$asked->getKey())
            ->send();
    }

    /**
     * Hand the visitor to a person: opens (or rejoins) the support
     * transcript, mirrors the request into the widget transcript and
     * streams the acknowledgement back as Server-Sent Events.
     */
    private function handoff(Site $site, WidgetConversation $conversation, string $question): Response
    {
        $conversation->messages()->create([
            'site_id' => $site->getKey(),
            'role' => ChatRole::User,
            'content' => $question,
            'credit_cost' => 0,
        ]);

        $assistant = $conversation->messages()->create([
            'site_id' => $site->getKey(),
            'role' => ChatRole::Assistant,
            'content' => self::HANDOFF_TEXT,
            'status' => MessageStatus::Complete,
            'credit_cost' => 0,
        ]);

        $conversation->increment('message_count', 2);

        $ticket = $this->support->start($conversation, $question);

        $this->escalate($conversation, $site, 'visitor_requested', automated: false);

        return $this->streamFrames(function (callable $emit) use ($ticket, $assistant): void {
            $emit('status', ['phase' => AnswerPhase::Reading->value]);
            $emit('delta', ['text' => self::HANDOFF_TEXT]);
            $emit('handoff', $this->supportPayload($ticket));
            $emit('done', ['id' => $assistant->getKey()]);
        });
    }

    /**
     * A follow-up while a human conversation is open: the message goes to
     * the support transcript and the staff side is notified — no RAG, no
     * quota, no credits.
     */
    private function supportReply(Site $site, WidgetConversation $conversation, SupportConversation $ticket, string $question): Response
    {
        $asked = $conversation->messages()->create([
            'site_id' => $site->getKey(),
            'role' => ChatRole::User,
            'content' => $question,
            'credit_cost' => 0,
        ]);

        $conversation->increment('message_count', 1);

        $ticket = $this->support->start($conversation, $question);

        return $this->streamFrames(function (callable $emit) use ($ticket, $asked): void {
            $emit('support', $this->supportPayload($ticket));
            $emit('done', ['id' => $asked->getKey()]);
        });
    }

    /**
     * Write pre-computed SSE frames in one shot: these streams carry no
     * model output, so there is nothing to flush incrementally.
     *
     * @param  callable(callable): void  $frames
     */
    private function streamFrames(callable $frames): Response
    {
        return response()->stream(function () use ($frames): void {
            ignore_user_abort(true);

            $emit = static function (string $event, array $data): void {
                echo 'event: '.$event."\n";
                echo 'data: '.json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n\n";

                if (ob_get_level() > 0) {
                    @ob_flush();
                }

                flush();
            };

            $frames($emit);
        }, 200, [
            'Content-Type' => 'text/event-stream; charset=utf-8',
            'Cache-Control' => 'no-cache, no-transform',
            'X-Accel-Buffering' => 'no',
            'Connection' => 'keep-alive',
        ]);
    }

    /**
     * The visitor's support conversation, live or resolved — the payload the
     * widget needs to render (and keep polling) its support mode.
     */
    private function latestSupport(WidgetConversation $conversation): ?SupportConversation
    {
        return SupportConversation::query()
            ->where('widget_conversation_id', $conversation->getKey())
            ->with('agent:id,name')
            ->latest('id')
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function supportPayload(SupportConversation $ticket): array
    {
        return [
            'conversation_id' => $ticket->getKey(),
            'status' => $ticket->status->value,
            'status_label' => $ticket->status->label(),
            'agent' => $ticket->agent?->name,
            'live' => $ticket->isLive(),
            'message_count' => (int) $ticket->message_count,
            // Everything the transcript already holds, so a widget that just
            // entered support mode starts its poll cursor here instead of
            // re-rendering lines it already shows (the welcome line, its own
            // request) as duplicates.
            'last_message_id' => (int) $ticket->messages()->max('id'),
        ];
    }

    /**
     * Support transcript rows for the widget, optionally only those newer
     * than the visitor's cursor.
     *
     * @return list<array<string, mixed>>
     */
    private function supportMessages(?SupportConversation $ticket, int $since): array
    {
        if ($ticket === null) {
            return [];
        }

        return $ticket->messages()
            ->with('sender:id,name')
            ->where('id', '>', $since)
            ->orderBy('id')
            ->get()
            ->map(fn (SupportMessage $message): array => [
                'id' => $message->getKey(),
                'role' => $message->role->value,
                'label' => match ($message->role) {
                    SupportMessageRole::User => 'You',
                    SupportMessageRole::Agent => $message->sender?->name ?? 'Support',
                    SupportMessageRole::System => null,
                },
                'content' => $message->content,
                'created_at' => $message->created_at->toIso8601String(),
            ])
            ->all();
    }

    /**
     * Thumbs up/down, which also feeds the owner's analytics.
     */
    public function feedback(Request $request, string $siteKey, WidgetConversation $conversation): JsonResponse
    {
        $site = $this->site($request, $siteKey);
        $this->authorizeConversation($request, $site, $conversation);

        $validated = $request->validate([
            'message_id' => ['required', 'integer'],
            'helpful' => ['required', 'boolean'],
        ]);

        $message = WidgetMessage::query()
            ->where('widget_conversation_id', $conversation->getKey())
            ->find($validated['message_id']);

        if ($message === null) {
            return response()->json(['message' => 'Message not found.'], 404);
        }

        $message->update(['was_helpful' => $validated['helpful']]);

        return response()->json(['ok' => true]);
    }

    /**
     * Retrieve across the site's documents, then generate.
     *
     * @param  (callable(string $delta): void)|null  $onDelta
     * @param  (callable(AnswerPhase): void)|null  $onStatus
     * @return array{content: string, sources: list<array<string, mixed>>, refused: bool, fallback: bool, model: string|null, latency_ms: int}
     */
    private function answer(
        Site $site,
        WidgetConversation $conversation,
        WidgetMessage $asked,
        string $question,
        ?callable $onDelta,
        float $startedAt,
        ?callable $onStatus = null,
    ): array {
        if ($this->intents->isCasual($question)) {
            if ($onStatus !== null) {
                $onStatus(AnswerPhase::Preparing);
            }

            $completion = $this->chatClient->complete(
                $this->prompts->buildCasual($question, $this->history($conversation, $asked)),
                $onDelta,
            );

            return [
                'content' => $completion->text,
                'sources' => [],
                'refused' => false,
                'fallback' => false,
                'model' => $completion->model,
                'latency_ms' => $this->elapsed($startedAt),
            ];
        }

        if ($onStatus !== null) {
            $onStatus(AnswerPhase::Searching);
        }

        $hits = $this->retrieve($site, $question);

        if ($onStatus !== null) {
            $onStatus(AnswerPhase::Preparing);
        }

        if ($hits === []) {
            $completion = $this->chatClient->complete(
                $this->prompts->buildFallback($question, $this->history($conversation, $asked)),
                $onDelta,
            );

            // No document matched: the model answers from its general knowledge
            // (or politely declines). Either way the owner should see it as a
            // fallback, not as a knowledge-backed success.
            return [
                'content' => $completion->text,
                'sources' => [],
                'refused' => false,
                'fallback' => true,
                'model' => $completion->model,
                'latency_ms' => $this->elapsed($startedAt),
            ];
        }

        $completion = $this->chatClient->complete(
            $this->prompts->build($question, $hits, $this->history($conversation, $asked)),
            $onDelta,
        );

        return [
            'content' => $completion->text,
            'sources' => $hits,
            'refused' => false,
            'fallback' => false,
            'model' => $completion->model,
            'latency_ms' => $this->elapsed($startedAt),
        ];
    }

    /**
     * Milliseconds from the visitor's send to the finished answer, which is
     * what the owner's outcome metrics should report.
     */
    private function elapsed(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }

    /**
     * Record widget engagement (load, launcher open, message sent, …).
     *
     * Best-effort by design: a failed beacon must never affect the chat, and no
     * identifier beyond the already-hashed visitor id is accepted.
     */
    public function events(Request $request, string $siteKey): JsonResponse
    {
        $site = $this->site($request, $siteKey);

        $validated = $request->validate([
            'type' => ['required', 'string', Rule::in(WidgetEvent::TYPES)],
            'visitor_id' => ['nullable', 'string', 'max:64'],
            'conversation_id' => ['nullable', 'integer'],
        ]);

        $conversation = null;

        if (! empty($validated['conversation_id'])) {
            $conversation = $site->conversations()
                ->whereKey($validated['conversation_id'])
                ->first();
        }

        WidgetEvent::query()->create([
            'site_id' => $site->getKey(),
            'workspace_id' => $site->workspace_id,
            'widget_conversation_id' => $conversation?->getKey(),
            'type' => $validated['type'],
            'visitor_id_hash' => empty($validated['visitor_id'])
                ? null
                : hash_hmac('sha256', $validated['visitor_id'], (string) config('app.key')),
        ]);

        return response()->json(['ok' => true]);
    }

    /**
     * Map an exception onto a short, human-readable reason code the dashboard
     * can group and explain ("model timeout", "rate limited", …).
     */
    private static function classifyFailure(Throwable $exception): string
    {
        $class = strtolower($exception::class);
        $message = strtolower($exception->getMessage());

        if (str_contains($class, 'timeout') || str_contains($message, 'timed out') || str_contains($message, 'timeout')) {
            return 'model_timeout';
        }

        if (str_contains($class, 'connection') || str_contains($message, 'could not resolve') || str_contains($message, 'connection')) {
            return 'model_unreachable';
        }

        if (str_contains($message, 'rate limit') || str_contains($message, 'too many requests') || str_contains($message, 'quota')) {
            return 'rate_limited';
        }

        if (str_contains($message, 'context') && str_contains($message, 'length')) {
            return 'context_too_long';
        }

        return 'server_error';
    }

    /**
     * Flag a conversation as escalated: the automated path when the answer
     * failed, the explicit path when the visitor asked for a person.
     */
    private function escalate(WidgetConversation $conversation, Site $site, string $reason, bool $automated = true): void
    {
        if ($conversation->escalated_at !== null) {
            return;
        }

        $conversation->forceFill([
            'escalated_at' => now(),
            'escalation_reason' => $reason,
        ])->save();

        WidgetConversationAuditLog::query()->create([
            'workspace_id' => $site->workspace_id,
            'site_id' => $site->getKey(),
            'conversation_id' => $conversation->getKey(),
            'action' => 'visitor.escalated',
            'details' => ['reason' => $reason, 'automated' => $automated],
        ]);

        $ticket = $this->supportTicket($conversation);

        $this->siteNotifier($site)
            ->type(NotificationType::ConversationEscalated)
            ->title($automated ? 'Visitor needs a human' : 'Visitor requested a human')
            ->body($automated
                ? 'The assistant could not answer on '.$site->name.' — the conversation was escalated.'
                : 'A visitor asked to speak with a person on '.$site->name.' — reply from the support inbox.')
            ->link(
                $ticket !== null ? route('admin.support.show', $ticket) : route('widget.leads.index', $site),
                $ticket !== null ? 'Open support inbox' : 'Open inbox',
            )
            ->meta(['reason' => $reason])
            ->dedupe('conversation.escalated.'.$conversation->getKey())
            ->send();
    }

    /**
     * The support ticket opened for this visitor conversation, if any.
     */
    private function supportTicket(WidgetConversation $conversation): ?SupportConversation
    {
        return SupportConversation::query()
            ->where('widget_conversation_id', $conversation->getKey())
            ->latest('id')
            ->first();
    }

    /**
     * Widget events reach the whole workspace, or just the owner for sites
     * that predate workspace tenancy.
     */
    private function siteNotifier(Site $site): Notifier
    {
        $notifier = Notifier::make();

        return $site->workspace_id === null
            ? $notifier->to($site->user)->workspace(null)
            : $notifier->toWorkspace($site->workspace_id);
    }

    /**
     * Hybrid search restricted to the documents this site is allowed to use.
     *
     * @return list<array<string, mixed>>
     */
    private function retrieve(Site $site, string $question): array
    {
        $documents = $site->documents()
            ->where('status', DocumentStatus::Processed)
            ->get();

        if ($documents->isEmpty()) {
            return [];
        }

        $query = $this->embeddings->embedOne($question);
        $poolSize = max((int) config('rag.top_k', 4), (int) config('rag.retrieval_pool', 20));
        $floor = (float) config('rag.min_similarity', 0.15);
        $snippetChars = (int) config('rag.snippet_chars', 400);
        $keywordCutoff = (float) config('rag.keyword_confidence', 0.5);

        $stored = [];
        $vectorList = [];
        $keywordList = [];
        $keywordScores = [];
        $vectorScores = [];
        $bestKeyword = 0.0;

        foreach ($documents as $document) {
            $chunks = $this->store->loadForDocument($document->getKey());

            if ($chunks === []) {
                continue;
            }

            $stored[$document->getKey()] = $chunks;

            foreach ($this->similarity->rank($query, $chunks, $poolSize) as $hit) {
                $key = $this->key($document->getKey(), (int) $hit['chunk']['chunk_index']);

                $vectorList[] = ['chunk_index' => $key];
                $vectorScores[$key] = $hit['score'];
            }

            foreach ($this->keywords->search($document->getKey(), $question, $poolSize, $chunks) as $hit) {
                $key = $this->key($document->getKey(), (int) $hit['chunk_index']);

                $keywordList[] = ['chunk_index' => $key];
                $keywordScores[$key] = $hit['score'];
                $bestKeyword = max($bestKeyword, (float) $hit['score']);
            }
        }

        if ($vectorList === [] && $keywordList === []) {
            return [];
        }

        $cutoff = $bestKeyword * $keywordCutoff;
        $hits = [];

        foreach (array_slice($this->fusion->fuse($vectorList, $keywordList), 0, (int) config('rag.top_k', 4)) as $candidate) {
            $key = (string) $candidate['chunk_index'];

            [$documentId, $chunkIndex] = array_pad(explode(':', $key, 2), 2, null);
            $chunk = $this->findChunk($stored[(int) $documentId] ?? [], (int) $chunkIndex);

            if ($chunk === null) {
                continue;
            }

            $document = $documents->firstWhere('id', (int) $documentId);

            if ($document === null) {
                continue;
            }

            $score = $vectorScores[$key] ?? 0.0;
            $keywordScore = $keywordScores[$key] ?? 0.0;
            $semantic = $score >= $floor;
            $lexical = $bestKeyword > 0.0 && $keywordScore >= $cutoff;

            if (! $semantic && ! $lexical) {
                continue;
            }

            $text = $this->expander->expand([
                'chunk_index' => (int) $chunk['chunk_index'],
                'text' => (string) $chunk['text'],
            ], $stored[(int) $documentId]);

            $hit = [
                'document' => $document?->filename ?? 'Document',
                'chunk_index' => (int) $chunk['chunk_index'],
                'page_from' => (int) $chunk['page_from'],
                'page_to' => (int) $chunk['page_to'],
                'score' => round($score, 4),
                'snippet' => trim(mb_substr($text, 0, $snippetChars)),
            ];

            if (! $semantic) {
                $hit['keyword_score'] = round($keywordScore, 4);
            }

            $hits[] = $hit;
        }

        return $hits;
    }

    private function key(int $documentId, int $chunkIndex): string
    {
        return $documentId.':'.$chunkIndex;
    }

    /**
     * @param  list<array<string, mixed>>  $chunks
     * @return array<string, mixed>|null
     */
    private function findChunk(array $chunks, int $chunkIndex): ?array
    {
        foreach ($chunks as $chunk) {
            if ((int) $chunk['chunk_index'] === $chunkIndex) {
                return $chunk;
            }
        }

        return null;
    }

    /**
     * Prior turns replayed to the model.
     *
     * `$before` is the user row just stored for this turn. Without it the
     * question is replayed from history *and* appended again by the prompt
     * builder, producing two identical adjacent user turns.
     *
     * @return list<array{role: string, content: string}>
     */
    private function history(WidgetConversation $conversation, ?WidgetMessage $before = null): array
    {
        $query = $conversation->messages()
            ->where('role', '!=', ChatRole::System->value)
            ->orderByDesc('id')
            ->limit(12);

        if ($before !== null) {
            $query->where('id', '<', $before->getKey());
        }

        return $query->get()
            ->reverse()
            ->filter(fn (WidgetMessage $message): bool => $message->content !== '' && ! $message->was_refused)
            ->map(fn (WidgetMessage $message): array => [
                'role' => $message->role->value,
                'content' => $message->content,
            ])
            ->values()
            ->all();
    }

    private function site(Request $request, string $siteKey): Site
    {
        $site = Site::query()->where('site_key', $siteKey)->first();

        // An unknown and a disabled site are indistinguishable on purpose.
        abort_if($site === null || ! $site->isLive(), 404);

        // A key pasted onto a foreign page must not be able to spend this
        // site's quota once the owner has locked the widget to a domain.
        // Requests from our own host (the live preview) are always allowed.
        $origin = $request->headers->get('Origin') ?: $request->headers->get('Referer');

        if ($origin !== null && $origin !== '') {
            $originHost = strtolower((string) parse_url($origin, PHP_URL_HOST));

            if ($originHost !== strtolower($request->getHost())) {
                abort_unless(
                    $site->allowsOrigin($origin),
                    403,
                    'This widget is not authorised for this domain.',
                );
            }
        }

        return $site;
    }

    /**
     * A conversation is reachable only by the visitor that opened it.
     *
     * The site key is public by design, so checking `site_id` alone let anyone
     * read, post into, and falsify analytics for every other visitor's thread
     * simply by walking the auto-increment id. `visitor_id` is a random value
     * the widget keeps in localStorage — it is not a secret, but it is not
     * guessable or enumerable either, which is exactly what an ownership check
     * on a public API needs.
     */
    private function authorizeConversation(Request $request, Site $site, WidgetConversation $conversation): void
    {
        abort_unless($conversation->site_id === $site->getKey(), 404);

        $validated = $request->validate([
            'visitor_id' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]{8,64}$/'],
        ], [
            'visitor_id.required' => 'A visitor id is required.',
            'visitor_id.regex' => 'That visitor id is not valid.',
        ]);

        abort_unless(
            hash_equals((string) $conversation->visitor_id, (string) $validated['visitor_id']),
            404,
        );
    }
}
