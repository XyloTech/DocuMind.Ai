<?php

namespace App\Http\Controllers;

use App\Enums\AnswerPhase;
use App\Enums\ChatRole;
use App\Enums\DocumentStatus;
use App\Enums\MessageStatus;
use App\Enums\NotificationType;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\Document;
use App\Models\User;
use App\Services\Ai\ChatClient;
use App\Services\Billing\CreditLedger;
use App\Services\Notifier;
use App\Services\RAG\DocumentSummarizer;
use App\Services\RAG\IntentClassifier;
use App\Services\RAG\PromptBuilder;
use App\Services\RAG\Retriever;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class ChatController extends Controller
{
    /**
     * Placeholder title for a conversation that has not been named yet. It is
     * reserved: `update()` rejects it so a user-chosen name can never be
     * mistaken for "still untitled" and silently overwritten.
     */
    private const string AUTO_TITLE = 'New chat';

    public function __construct(
        private readonly Retriever $retriever,
        private readonly PromptBuilder $prompts,
        private readonly ChatClient $chatClient,
        private readonly CreditLedger $ledger,
        private readonly IntentClassifier $intents,
        private readonly DocumentSummarizer $summarizer,
    ) {}

    /**
     * Workspace for the most recent conversation, or an empty one.
     */
    public function index(Request $request): View|RedirectResponse
    {
        $latest = $this->chats($request->user())->first();

        if ($latest !== null) {
            return redirect()->route('chats.show', $latest);
        }

        return $this->workspace($request, null);
    }

    /**
     * Workspace for one conversation.
     */
    public function show(Request $request, Chat $chat): View
    {
        Gate::authorize('view', $chat);

        return $this->workspace($request, $chat);
    }

    /**
     * Start a conversation about a document.
     */
    public function store(Request $request): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'document_id' => ['required', 'integer', 'exists:documents,id'],
        ], [
            'document_id.required' => 'Choose a document to chat about.',
            'document_id.exists' => 'That document no longer exists.',
        ]);

        if (! $request->user()->allowsHistoryStorage()) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'Chat history storage is off. Turn it on under Privacy & data to start a conversation.',
                    'redirect' => route('settings.privacy'),
                ], 409);
            }

            return redirect()
                ->route('settings.privacy')
                ->with('status', 'Turn on "Save my chat history" to start a conversation.');
        }

        $document = Document::query()->findOrFail($validated['document_id']);

        Gate::authorize('view', $document);

        if (! $document->isProcessed()) {
            throw ValidationException::withMessages([
                'document_id' => 'That document is still being processed. Try again once it shows Ready.',
            ]);
        }

        $chat = Chat::create([
            'user_id' => $request->user()->getKey(),
            'workspace_id' => $request->session()->get('active_workspace_id'),
            'document_id' => $document->getKey(),
            'title' => self::AUTO_TITLE,
            'last_message_at' => now(),
        ]);

        if ($request->expectsJson()) {
            return response()->json([
                'chat' => $chat,
                'url' => route('chats.show', $chat),
            ], 201);
        }

        return redirect()->route('chats.show', $chat);
    }

    /**
     * Rename a conversation title.
     */
    public function update(Request $request, Chat $chat): JsonResponse|RedirectResponse
    {
        Gate::authorize('update', $chat);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:120', 'not_regex:/^'.preg_quote(self::AUTO_TITLE, '/').'$/'],
        ], [
            'title.not_regex' => 'That name is reserved. Pick another one.',
        ]);

        $chat->update([
            'title' => trim($validated['title']),
        ]);

        if ($request->expectsJson()) {
            return response()->json([
                'chat' => $chat,
                'title' => $chat->title,
            ]);
        }

        return back()->with('status', 'Chat renamed.');
    }

    /**
     * Remove a conversation (the document and its chunks are untouched).
     */
    public function destroy(Request $request, Chat $chat): Response
    {
        Gate::authorize('delete', $chat);

        $chat->delete();

        if ($request->expectsJson()) {
            return response()->noContent();
        }

        return redirect()
            ->route('chats.index')
            ->with('status', 'Chat deleted.');
    }

    /**
     * Record whether an assistant answer was useful.
     */
    public function feedback(Request $request, Chat $chat, ChatMessage $message): JsonResponse
    {
        Gate::authorize('update', $chat);

        // Form-encoded posts arrive as the strings "true"/"false", which the
        // boolean rule rejects. Normalise before validating so the browser and
        // an API client can both use the same endpoint.
        $raw = $request->input('helpful');

        $request->merge([
            'helpful' => is_bool($raw) || $raw === null
                ? $raw
                : match (strtolower((string) $raw)) {
                    'true', '1', 'yes', 'on' => true,
                    'false', '0', 'no', 'off' => false,
                    default => null,
                },
        ]);

        $validated = $request->validate([
            'helpful' => ['nullable', 'boolean'],
        ]);

        if ($message->chat_id !== $chat->getKey() || $message->role !== ChatRole::Assistant) {
            return response()->json(['message' => 'Message not found.'], 404);
        }

        $message->update(['was_helpful' => $validated['helpful'] ?? null]);

        return response()->json([
            'ok' => true,
            'was_helpful' => $message->was_helpful,
        ]);
    }

    /**
     * Answer one message, streaming the reply as Server-Sent Events.
     */
    public function messages(Request $request, Chat $chat): Response
    {
        Gate::authorize('update', $chat);

        $validated = $request->validate([
            'message' => ['required', 'string', 'max:4000'],
        ], [
            'message.required' => 'Type a message first.',
            'message.max' => 'That message is too long. Keep it under 4,000 characters.',
        ]);

        $question = trim($validated['message']);

        if ($question === '') {
            throw ValidationException::withMessages(['message' => 'Type a message first.']);
        }

        if (! $request->user()->allowsHistoryStorage()) {
            return $this->reject(
                $request,
                'Chat history storage is off. Turn it on under Privacy & data to keep chatting.',
                409,
            );
        }

        if (! $chat->isAnswerable()) {
            return $this->reject(
                $request,
                'This chat has no ready document. Pick another document or upload a new PDF.',
                422,
            );
        }

        $user = $request->user();
        $cost = max(0, (int) config('billing.credits_per_message', 1));

        if ($user->credits < $cost || ! $this->ledger->deduct($user, $cost)) {
            return $this->reject(
                $request,
                'You are out of credits. Top up from the Billing page to keep chatting.',
                402,
            );
        }

        $startedAt = microtime(true);

        $userMessage = $chat->messages()->create([
            'role' => ChatRole::User,
            'content' => $question,
            'status' => MessageStatus::Complete,
        ]);

        $assistant = $chat->messages()->create([
            'role' => ChatRole::Assistant,
            'content' => '',
            'status' => MessageStatus::Streaming,
            'credits_cost' => $cost,
        ]);

        $chat->fill([
            'title' => $chat->title === self::AUTO_TITLE ? $this->autoTitle($question) : $chat->title,
            'last_message_at' => now(),
        ])->save();

        $chat->increment('message_count', 2);

        $history = $this->history($chat, $userMessage);

        if (! config('rag.stream_enabled')) {
            try {
                $result = $this->generate($chat, $question, $history, null, $startedAt);
                $this->persist($assistant, $user, $cost, $result);
            } catch (Throwable $exception) {
                $this->fail($assistant, $user, $cost, $exception);

                return $this->reject($request, self::userFacingError(), 502);
            }

            return response()->json([
                'message' => $assistant->fresh(),
                'credits' => $user->fresh()->credits,
            ]);
        }

        return response()->stream(function () use ($chat, $assistant, $user, $cost, $question, $history, $startedAt): void {
            // A closed socket must not abort the answer half-way: the assistant
            // row would stay `streaming` forever and the deducted credit would
            // never come back. Finish the work, then persist a terminal state.
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
                // First byte of the stream: it flushes the headers and lets the
                // browser paint a real status before any retrieval or model call.
                $onStatus = static function (AnswerPhase $phase) use ($emit): void {
                    $emit('status', ['phase' => $phase->value]);
                };

                $onStatus(AnswerPhase::Reading);

                $onDelta = static function (string $delta) use ($emit): void {
                    $emit('delta', ['text' => $delta]);
                };

                $result = $this->generate($chat, $question, $history, $onDelta, $startedAt, $onStatus);
                $this->persist($assistant, $user, $cost, $result);

                $emit('sources', ['sources' => $result['sources']]);
                $emit('done', [
                    'message_id' => $assistant->getKey(),
                    'credits' => (int) $user->fresh()->credits,
                ]);
            } catch (Throwable $exception) {
                $this->fail($assistant, $user, $cost, $exception);

                $emit('error', ['message' => self::userFacingError()]);
            }
        }, 200, [
            'Content-Type' => 'text/event-stream; charset=utf-8',
            'Cache-Control' => 'no-cache, no-transform',
            'X-Accel-Buffering' => 'no',
            'Connection' => 'keep-alive',
        ]);
    }

    /**
     * Starter questions for a document, used by the empty chat screen.
     */
    public function suggestions(Request $request, Document $document): JsonResponse
    {
        Gate::authorize('view', $document);

        return response()->json([
            'questions' => $document->suggestedQuestions(),
        ]);
    }

    /**
     * Summarise a whole document (map-reduce) without opening a chat.
     */
    public function summarize(Request $request, Document $document): Response
    {
        Gate::authorize('view', $document);

        if (! $document->isProcessed()) {
            return $this->reject($request, 'This document is still being processed.', 422);
        }

        $user = $request->user();
        $cost = max(0, (int) config('billing.credits_per_message', 1));

        if ($user->credits < $cost || ! $this->ledger->deduct($user, $cost)) {
            return $this->reject($request, 'You are out of credits. Top up from the Billing page to keep chatting.', 402);
        }

        try {
            $summary = $this->summarizer->summarize($document);
        } catch (Throwable $exception) {
            report($exception);
            $this->ledger->refund($user, $cost);

            return $this->reject($request, 'The summary could not be generated. Your credit was refunded.', 502);
        }

        return response()->json([
            'summary' => $summary,
            'credits' => (int) $user->fresh()->credits,
        ]);
    }

    /**
     * Retrieve context, then generate. Emits `delta` events while answering.
     *
     * Greetings and other small talk skip retrieval entirely so the local model
     * can answer naturally instead of returning the "not in the document" line.
     *
     * @param  list<array{role: string, content: string}>  $history
     * @param  (callable(string $delta): void)|null  $onDelta
     * @param  (callable(AnswerPhase): void)|null  $onStatus
     * @return array{content: string, sources: list<array<string, mixed>>, refused: bool, model: string|null, prompt_tokens: int, completion_tokens: int, latency_ms: int}
     *
     * @throws Throwable
     */
    private function generate(Chat $chat, string $question, array $history, ?callable $onDelta, float $startedAt, ?callable $onStatus = null): array
    {
        if ($this->intents->isCasual($question)) {
            if ($onStatus !== null) {
                $onStatus(AnswerPhase::Preparing);
            }

            $completion = $this->chatClient->complete(
                $this->prompts->buildCasual($question, $history),
                $onDelta,
            );

            return [
                'content' => $completion->text,
                'sources' => [],
                'refused' => false,
                'model' => $completion->model,
                'prompt_tokens' => $completion->promptTokens,
                'completion_tokens' => $completion->completionTokens,
                'latency_ms' => $this->elapsed($startedAt),
            ];
        }

        if ($onStatus !== null) {
            $onStatus(AnswerPhase::Searching);
        }

        $hits = $this->retriever->retrieve($chat->document, $question);

        if ($onStatus !== null) {
            $onStatus(AnswerPhase::Preparing);
        }

        if ($hits === []) {
            $completion = $this->chatClient->complete(
                $this->prompts->buildFallback($question, $history),
                $onDelta,
            );

            return [
                'content' => $completion->text,
                'sources' => [],
                'refused' => false,
                'model' => $completion->model,
                'prompt_tokens' => $completion->promptTokens,
                'completion_tokens' => $completion->completionTokens,
                'latency_ms' => $this->elapsed($startedAt),
            ];
        }

        $completion = $this->chatClient->complete(
            $this->prompts->build($question, $hits, $history),
            $onDelta,
        );

        return [
            'content' => $completion->text,
            'sources' => $hits,
            'refused' => false,
            'model' => $completion->model,
            'prompt_tokens' => $completion->promptTokens,
            'completion_tokens' => $completion->completionTokens,
            'latency_ms' => $this->elapsed($startedAt),
        ];
    }

    /**
     * Save the finished answer, then give the credit back if nothing was
     * generated (no passage cleared the similarity floor).
     *
     * @param  array{content: string, sources: list<array<string, mixed>>, refused: bool, model: string|null, prompt_tokens: int, completion_tokens: int, latency_ms: int}  $result
     */
    private function persist(ChatMessage $assistant, User $user, int $cost, array $result): void
    {
        $assistant->update([
            'content' => $result['content'],
            'status' => MessageStatus::Complete,
            'model_used' => $result['model'],
            'prompt_tokens' => $result['prompt_tokens'],
            'completion_tokens' => $result['completion_tokens'],
            'latency_ms' => $result['latency_ms'],
            'sources' => $result['sources'],
            'credits_cost' => $result['refused'] ? 0 : $cost,
        ]);

        if ($result['refused']) {
            $this->ledger->refund($user, $cost);
        }
    }

    /**
     * Give the credit back and flag the message as failed.
     *
     * Runs inside a `catch` (and, for the streaming path, after response bytes
     * have already been written), so it must never throw: an exception here
     * would leave the message stuck in `streaming` and the credit spent.
     */
    private function fail(ChatMessage $assistant, User $user, int $cost, Throwable $exception): void
    {
        report($exception);

        try {
            $this->ledger->refund($user, $cost);

            $assistant->update([
                'status' => MessageStatus::Failed,
                'credits_cost' => 0,
            ]);

            Notifier::make()
                ->type(NotificationType::AiFailed)
                ->to($user)
                ->title('The assistant could not answer')
                ->body('Your last question failed and the credit was refunded. Try again in a moment.')
                ->link(route('chats.show', $assistant->chat_id), 'Open chat')
                ->workspace($assistant->chat?->workspace_id)
                ->dedupe('ai.failed.'.$assistant->getKey())
                ->send();
        } catch (Throwable $secondary) {
            report($secondary);
        }
    }

    /**
     * Never send an internal exception message (SQL, provider payloads) to the
     * browser — the user only needs to know it failed and was refunded.
     */
    private static function userFacingError(): string
    {
        return 'Something went wrong generating that answer. Your credit was refunded.';
    }

    /**
     * Prior turns replayed to the model for follow-up questions.
     *
     * @return list<array{role: string, content: string}>
     */
    private function history(Chat $chat, ChatMessage $before): array
    {
        $limit = max(1, (int) config('rag.history_messages', 6)) * 4;

        return $chat->messages()
            ->where('id', '<', $before->getKey())
            ->where('status', MessageStatus::Complete->value)
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->reverse()
            ->filter(fn (ChatMessage $message): bool => $message->role->isHistory() && trim($message->content) !== '')
            ->map(fn (ChatMessage $message): array => [
                'role' => $message->role->value,
                'content' => $message->content,
            ])
            ->values()
            ->all();
    }

    private function workspace(Request $request, ?Chat $chat): View
    {
        $user = $request->user();

        $workspaceId = $request->session()->get('active_workspace_id');

        return view('chats.workspace', [
            'user' => $user,
            'chats' => $this->chats($user, $workspaceId)->get(),
            'documents' => Document::query()
                ->where('user_id', $user->getKey())
                ->when($workspaceId !== null, fn ($query) => $query->where('workspace_id', $workspaceId))
                ->where('status', DocumentStatus::Processed)
                ->orderByDesc('created_at')
                ->get(['id', 'filename', 'page_count', 'chunk_count', 'created_at']),
            'chat' => $chat,
            'messages' => $chat?->messages()->orderBy('id')->get() ?? collect(),
            'cost' => (int) config('billing.credits_per_message', 1),
            'historyEnabled' => $user->allowsHistoryStorage(),
        ]);
    }

    private function chats(User $user, ?int $workspaceId = null): Builder
    {
        return Chat::query()
            ->where('user_id', $user->getKey())
            ->when($workspaceId !== null, fn ($query) => $query->where('workspace_id', $workspaceId))
            ->with(['document:id,filename,status,page_count,chunk_count'])
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->limit(50);
    }

    /**
     * Every caller of this endpoint is the fetch() based chat client, which
     * sends `Accept: text/event-stream`. `expectsJson()` is false for that
     * header, so a redirect used to be returned and then parsed as an empty
     * SSE stream — the user saw a blank bubble and lost their question.
     */
    private function reject(Request $request, string $message, int $status): Response
    {
        return response()->json([
            'message' => $message,
            'credits' => (int) ($request->user()?->credits ?? 0),
        ], $status);
    }

    /**
     * Derive a conversation title from the first question, cutting on a word
     * boundary so titles never end mid-word.
     */
    private function autoTitle(string $question): string
    {
        $limit = 60;
        $title = trim(preg_replace('/\s+/u', ' ', $question) ?? $question);

        if (mb_strlen($title) <= $limit) {
            return $title;
        }

        $clipped = mb_substr($title, 0, $limit);
        $lastSpace = mb_strrpos($clipped, ' ');

        if ($lastSpace !== false && $lastSpace > 0) {
            $clipped = mb_substr($clipped, 0, $lastSpace);
        }

        return rtrim($clipped).'…';
    }

    private function elapsed(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }
}
