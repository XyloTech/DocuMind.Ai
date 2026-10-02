<?php

namespace App\Http\Controllers\Widget;

use App\Enums\ChatRole;
use App\Enums\DocumentStatus;
use App\Enums\MessageStatus;
use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Models\WidgetConversation;
use App\Models\WidgetConversationAuditLog;
use App\Models\WidgetEvent;
use App\Models\WidgetMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The owner's operational dashboard: what visitors asked, whether the bot
 * answered, how fast, from which knowledge, and where a human is needed.
 *
 * Everything is scoped to one site (and therefore one workspace) — no query in
 * this controller can cross a tenant boundary. The same payload serves the
 * rendered page and the 30-second polling refresh, so the numbers on screen
 * never disagree with the JSON behind them.
 */
class WidgetAnalyticsController extends Controller
{
    /** Reason codes the widget records, with the label and fix shown to the owner. */
    private const array FAILURE_REASONS = [
        'model_timeout' => ['Model timeout', 'The AI provider did not answer in time. Retry the question or raise the model timeout.'],
        'model_unreachable' => ['Model unreachable', 'The AI provider could not be contacted. Check the API key, base URL and network.'],
        'rate_limited' => ['Rate limited', 'The provider throttled the request. Wait a moment or raise the plan limit.'],
        'context_too_long' => ['Context too long', 'The question plus knowledge exceeded the model context window. Link fewer or smaller documents.'],
        'empty_response' => ['Empty response', 'The model returned nothing. Retry, or check the model configuration.'],
        'server_error' => ['Server error', 'An unexpected error occurred. Check storage/logs/laravel.log for the stack trace.'],
    ];

    /**
     * Human label for a stored failure reason, used by the transcript view.
     */
    public static function failureLabel(?string $reason): string
    {
        return self::FAILURE_REASONS[$reason ?? 'server_error'][0] ?? 'Unknown error';
    }

    public function __invoke(Request $request, Site $site): View|JsonResponse
    {
        Gate::authorize('view', $site);

        $filters = $this->validatedFilters($request);
        $payload = $this->payload($request, $site, $filters);

        if ($request->expectsJson()) {
            return response()->json([
                ...$payload,
                'feed_html' => view('widget.partials.activity-feed', [
                    'site' => $site,
                    'messages' => $payload['recent'],
                    'canSeeLeads' => Gate::allows('viewLeads', $site),
                ])->render(),
            ])->header('Cache-Control', 'no-store, private');
        }

        return view('widget.analytics', [
            'site' => $site,
            'filters' => $filters,
            'metrics' => $payload,
            'canSeeLeads' => Gate::allows('viewLeads', $site),
        ]);
    }

    /**
     * CSV export for the selected range: one row per conversation with its
     * outcome counts, timing and (for authorized users) the visitor email.
     */
    public function export(Request $request, Site $site): StreamedResponse
    {
        Gate::authorize('view', $site);

        $filters = $this->validatedFilters($request);
        $canSeeLeads = Gate::allows('viewLeads', $site);
        $since = $this->since($filters);

        $this->auditExport($request, $site, $filters);

        $filename = Str::slug($site->name).'-activity-'.$since->toDateString().'.csv';

        return response()->streamDownload(function () use ($canSeeLeads, $site, $since): void {
            $out = fopen('php://output', 'w');

            fputcsv($out, [
                'conversation_id',
                'created_at',
                'closed_at',
                'escalated_at',
                'escalation_reason',
                'resolution_minutes',
                'status',
                'classification',
                'follow_up_status',
                'message_count',
                'successful',
                'fallback',
                'failed',
                'unanswered',
                'avg_response_ms',
                'visitor_email',
                'consent_at',
            ]);

            WidgetConversation::query()
                ->where('site_id', $site->getKey())
                ->where('created_at', '>=', $since)
                ->orderBy('id')
                ->lazyById(100, 'id', function (WidgetConversation $conversation) use ($canSeeLeads, $out): void {
                    $messages = $conversation->messages()->where('role', ChatRole::Assistant->value)->get();

                    fputcsv($out, [
                        $conversation->getKey(),
                        $conversation->created_at->toIso8601String(),
                        $conversation->resolved_at?->toIso8601String(),
                        $conversation->escalated_at?->toIso8601String(),
                        $conversation->escalation_reason,
                        $conversation->resolved_at?->diffInMinutes($conversation->created_at),
                        $conversation->status,
                        $conversation->classification,
                        $conversation->follow_up_status,
                        $conversation->message_count,
                        $messages->filter(fn (WidgetMessage $message): bool => $message->status === MessageStatus::Complete && ! $message->was_fallback && ! $message->was_refused)->count(),
                        $messages->where('was_fallback', true)->count(),
                        $messages->where('status', MessageStatus::Failed)->count(),
                        $messages->where('was_refused', true)->count(),
                        (int) round((float) $messages->avg('latency_ms')),
                        $canSeeLeads ? ($conversation->safeVisitorEmail() ?? '') : '',
                        $canSeeLeads ? $conversation->visitor_email_consent_at?->toIso8601String() : '',
                    ]);
                });

            fclose($out);
        }, $filename, [
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * @param  array{days: int, search: string, status: string, outcome: string, sort: string}  $filters
     * @return array<string, mixed>
     */
    private function payload(Request $request, Site $site, array $filters): array
    {
        $since = $this->since($filters);

        $assistant = WidgetMessage::query()
            ->where('site_id', $site->getKey())
            ->where('role', ChatRole::Assistant->value)
            ->where('created_at', '>=', $since);

        $userMessages = WidgetMessage::query()
            ->where('site_id', $site->getKey())
            ->where('role', ChatRole::User->value)
            ->where('created_at', '>=', $since);

        $successful = (clone $assistant)
            ->where('status', MessageStatus::Complete)
            ->where('was_fallback', false)
            ->where('was_refused', false)
            ->count();
        $fallback = (clone $assistant)->where('was_fallback', true)->count();
        $failedRows = (clone $assistant)->where('status', MessageStatus::Failed);
        $failed = (clone $failedRows)->count();
        $refused = (clone $assistant)->where('was_refused', true)->count();

        $userCount = (clone $userMessages)->count();
        $answerCount = (clone $assistant)->where('status', MessageStatus::Complete)->count();
        $unanswered = max(0, $userCount - $answerCount);

        $failureReasons = (clone $failedRows)
            ->groupBy('error_reason')
            ->selectRaw('error_reason, COUNT(*) as total')
            ->pluck('total', 'error_reason')
            ->map(fn ($total, $reason): array => [
                'reason' => (string) ($reason ?? 'server_error'),
                'label' => self::FAILURE_REASONS[$reason ?? 'server_error'][0] ?? 'Unknown error',
                'hint' => self::FAILURE_REASONS[$reason ?? 'server_error'][1] ?? 'Check storage/logs/laravel.log.',
                'value' => (int) $total,
            ])
            ->sortByDesc('value')
            ->values()
            ->all();

        $latency = (clone $assistant)
            ->where('status', MessageStatus::Complete)
            ->whereNotNull('latency_ms')
            ->avg('latency_ms');

        $conversations = WidgetConversation::query()
            ->where('site_id', $site->getKey())
            ->where('created_at', '>=', $since);

        $active = (clone $conversations)->where('status', 'open')->count();
        $completed = (clone $conversations)->whereNotNull('resolved_at')->count();
        $escalated = (clone $conversations)->whereNotNull('escalated_at')->count();

        // Averaged in PHP rather than SQL: TIMESTAMPDIFF is MySQL-only and the
        // suite runs against SQLite, so portability beats one round trip.
        $resolutionMinutes = (clone $conversations)
            ->whereNotNull('resolved_at')
            ->get(['created_at', 'resolved_at'])
            ->avg(fn (WidgetConversation $conversation): float => $conversation->resolved_at->diffInSeconds($conversation->created_at) / 60);

        $feedback = WidgetMessage::query()
            ->where('site_id', $site->getKey())
            ->whereNotNull('was_helpful')
            ->where('created_at', '>=', $since);

        $conversationCount = (clone $conversations)->count();
        $emails = (clone $conversations)->whereNotNull('visitor_email_consent_at')->count();

        return [
            'range' => ['days' => $filters['days'], 'since' => $since->toDateString()],
            'filters' => $filters,
            'totals' => [
                'conversations' => $conversationCount,
                'messages' => (clone $userMessages)->count() + (clone $assistant)->count(),
                'questions' => $userCount,
                'successful' => $successful,
                'failed' => $failed,
                'fallback' => $fallback,
                'refused' => $refused,
                'unanswered' => $unanswered,
                'emails' => $emails,
                'consent_rate' => $conversationCount > 0 ? (int) round($emails / $conversationCount * 100) : 0,
                'active' => $active,
                'completed' => $completed,
                'escalated' => $escalated,
                'helpful' => (clone $feedback)->where('was_helpful', true)->count(),
                'not_helpful' => (clone $feedback)->where('was_helpful', false)->count(),
                'avg_response_ms' => (int) round((float) $latency),
                'avg_resolution_minutes' => (int) round((float) $resolutionMinutes),
            ],
            'outcomes' => $this->outcomes($successful, $fallback, $failed, $refused, $unanswered),
            'failures' => $failureReasons,
            'daily' => $this->daily($site, $since),
            'knowledge' => $this->knowledge($site, $since),
            'documents' => $this->documentStatus($site),
            'events' => $this->events($site, $since),
            'recent' => $this->recent($site),
            'transcripts' => $this->transcripts($site, $filters),
            'updated_at' => now()->format('H:i:s T'),
        ];
    }

    /**
     * @return list<array{key: string, label: string, value: int, percent: int, hint: string}>
     */
    private function outcomes(int $successful, int $fallback, int $failed, int $refused, int $unanswered): array
    {
        $rows = [
            ['key' => 'successful', 'label' => 'Successful', 'value' => $successful, 'hint' => 'Answered from the linked support knowledge.'],
            ['key' => 'fallback', 'label' => 'Fallback', 'value' => $fallback, 'hint' => 'No matching knowledge — the bot replied from general guidance or asked to contact support.'],
            ['key' => 'failed', 'label' => 'Failed', 'value' => $failed, 'hint' => 'The model or pipeline errored. Each failure lists a reason below.'],
            ['key' => 'refused', 'label' => 'Declined', 'value' => $refused, 'hint' => 'The bot explicitly said it does not have a confirmed answer.'],
            ['key' => 'unanswered', 'label' => 'Unanswered', 'value' => $unanswered, 'hint' => 'Questions that never received a stored answer (visitor left, quota, or interrupted request).'],
        ];

        $total = array_sum(array_column($rows, 'value'));

        foreach ($rows as $index => $row) {
            $rows[$index]['percent'] = $total > 0 ? (int) round($row['value'] / $total * 100) : 0;
        }

        return $rows;
    }

    /**
     * Per-day message volume split by outcome, plus the average response time.
     *
     * @return array{days: array<string, array{messages: int, successful: int, failed: int, fallback: int}>, latency: array<string, int>}
     */
    private function daily(Site $site, \DateTimeInterface $since): array
    {
        $rows = WidgetMessage::query()
            ->where('site_id', $site->getKey())
            ->where('created_at', '>=', $since)
            ->get(['id', 'role', 'status', 'was_fallback', 'was_refused', 'latency_ms', 'created_at']);

        $days = [];
        $latency = [];

        for ($offset = 0; $offset < 90; $offset++) {
            $day = now()->subDays($offset)->toDateString();

            if ($day < $since->format('Y-m-d')) {
                break;
            }

            $days[$day] = ['messages' => 0, 'successful' => 0, 'failed' => 0, 'fallback' => 0];
        }

        foreach ($rows as $row) {
            $day = $row->created_at->toDateString();

            if (! isset($days[$day])) {
                continue;
            }

            $days[$day]['messages']++;

            if ($row->role === ChatRole::User) {
                continue;
            }

            if ($row->status === MessageStatus::Failed) {
                $days[$day]['failed']++;
            } elseif ($row->was_fallback) {
                $days[$day]['fallback']++;
            } elseif ($row->status === MessageStatus::Complete && ! $row->was_refused) {
                $days[$day]['successful']++;
            }
        }

        foreach ($rows as $row) {
            if ($row->role !== ChatRole::Assistant || $row->latency_ms === null) {
                continue;
            }

            $day = $row->created_at->toDateString();
            $latency[$day] ??= [];
            $latency[$day][] = (int) $row->latency_ms;
        }

        $latency = array_map(
            fn (array $samples): int => (int) round(array_sum($samples) / max(1, count($samples))),
            $latency,
        );

        ksort($days);
        ksort($latency);

        return ['days' => $days, 'latency' => $latency];
    }

    /**
     * Which documents actually powered answers, and how the linked knowledge is
     * processing right now.
     *
     * @return array{sources: list<array{document: string, uses: int, percent: int}>, cache_hit: int}
     */
    private function knowledge(Site $site, \DateTimeInterface $since): array
    {
        $uses = [];
        $cacheHit = 0;

        WidgetMessage::query()
            ->where('site_id', $site->getKey())
            ->where('role', ChatRole::Assistant->value)
            ->where('created_at', '>=', $since)
            ->whereNotNull('sources')
            ->where('sources', '!=', '[]')
            ->orderByDesc('id')
            ->limit(5000)
            ->get(['sources'])
            ->each(function (WidgetMessage $message) use (&$cacheHit, &$uses): void {
                foreach ($message->sourceBadges() as $source) {
                    $document = (string) ($source['document'] ?? '');

                    if ($document === '') {
                        continue;
                    }

                    $uses[$document] = ($uses[$document] ?? 0) + 1;
                    $cacheHit++;
                }
            });

        arsort($uses);
        $top = (int) max(array_values($uses ?: [0]));

        return [
            'sources' => collect($uses)
                ->take(8)
                ->map(fn (int $count, string $document): array => [
                    'document' => $document,
                    'uses' => $count,
                    'percent' => $top > 0 ? (int) round($count / $top * 100) : 0,
                ])
                ->values()
                ->all(),
            'cache_hit' => $cacheHit,
        ];
    }

    /**
     * @return list<array{status: string, label: string, count: int, hint: string}>
     */
    private function documentStatus(Site $site): array
    {
        $counts = $site->documents()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $rows = [
            DocumentStatus::Pending->value => ['label' => 'Queued', 'hint' => 'Waiting for the queue worker.'],
            DocumentStatus::Processing->value => ['label' => 'Processing', 'hint' => 'Being parsed, chunked and embedded.'],
            DocumentStatus::Processed->value => ['label' => 'Ready', 'hint' => 'Indexed and available to the assistant.'],
            DocumentStatus::Failed->value => ['label' => 'Failed', 'hint' => 'Could not be processed — open Knowledge to retry.'],
        ];

        $out = [];

        foreach ($rows as $status => $meta) {
            $out[] = [
                'status' => $status,
                'label' => $meta['label'],
                'count' => (int) ($counts[$status] ?? 0),
                'hint' => $meta['hint'],
            ];
        }

        return $out;
    }

    /**
     * Widget engagement: loads, launcher opens and messages sent.
     *
     * @return array{loads: int, opens: int, closes: int, messages: int, suggestions: int, engagement_rate: int, unique_visitors: int}
     */
    private function events(Site $site, \DateTimeInterface $since): array
    {
        $counts = WidgetEvent::query()
            ->where('site_id', $site->getKey())
            ->where('created_at', '>=', $since)
            ->selectRaw('type, COUNT(*) as total')
            ->groupBy('type')
            ->pluck('total', 'type');

        $loads = (int) ($counts['widget_loaded'] ?? 0);
        $opens = (int) ($counts['launcher_open'] ?? 0);
        $messages = (int) ($counts['message_sent'] ?? 0);
        $closes = (int) ($counts['launcher_close'] ?? 0);
        $suggestions = (int) ($counts['suggestion_click'] ?? 0);

        $unique = WidgetEvent::query()
            ->where('site_id', $site->getKey())
            ->where('created_at', '>=', $since)
            ->whereNotNull('visitor_id_hash')
            ->distinct()
            ->count('visitor_id_hash');

        return [
            'loads' => $loads,
            'opens' => $opens,
            'closes' => $closes,
            'messages' => $messages,
            'suggestions' => $suggestions,
            // One load can be opened and closed many times, so the raw ratio is a
            // frequency rather than a rate. Report it as a share of loads, capped
            // at 100, so the dashboard never shows "Open rate: 314%".
            'engagement_rate' => min(100, $loads > 0 ? (int) round($opens / $loads * 100) : ($opens > 0 ? 100 : 0)),
            'unique_visitors' => (int) $unique,
        ];
    }

    /**
     * The newest answers on this site — what the polling refresh swaps in so
     * new activity appears without a reload.
     *
     * @return list<array<string, mixed>>
     */
    private function recent(Site $site): array
    {
        return WidgetMessage::query()
            ->where('site_id', $site->getKey())
            ->where('role', ChatRole::Assistant->value)
            ->with('conversation:id,status,escalated_at')
            ->orderByDesc('id')
            ->limit(8)
            ->get()
            ->map(fn (WidgetMessage $message): array => [
                'id' => $message->getKey(),
                'conversation_id' => $message->widget_conversation_id,
                'preview' => Str::limit(strip_tags($message->safeContent() ?? 'Message preview unavailable.'), 140),
                'status' => $message->status->value,
                'error_reason' => $message->error_reason,
                'was_fallback' => $message->was_fallback,
                'was_refused' => $message->was_refused,
                'was_helpful' => $message->was_helpful,
                'sources' => collect($message->sourceBadges())
                    ->map(fn (array $source): ?string => $source['document'] ?? null)
                    ->filter()
                    ->unique()
                    ->values()
                    ->all(),
                'latency_ms' => $message->latency_ms,
                'escalated' => $message->conversation?->escalated_at !== null,
                'asked_at' => $message->created_at->diffForHumans(null, true),
                'created_at' => $message->created_at->toIso8601String(),
            ])
            ->all();
    }

    /**
     * Paginated transcripts with timestamps, sources and per-conversation
     * outcome flags — filterable by search, status and outcome.
     *
     * @param  array{days: int, search: string, status: string, outcome: string, sort: string}  $filters
     */
    private function transcripts(Site $site, array $filters): LengthAwarePaginator
    {
        $since = $this->since($filters);

        $query = WidgetConversation::query()
            ->where('site_id', $site->getKey())
            ->where('created_at', '>=', $since)
            ->withCount(['messages as assistant_count' => fn ($message) => $message->where('role', ChatRole::Assistant->value)])
            ->with(['messages' => fn ($message) => $message->orderByDesc('id')->limit(50), 'assignedUser:id,name']);

        if ($filters['search'] !== '') {
            $needle = mb_strtolower($filters['search']);

            if (filter_var($needle, FILTER_VALIDATE_EMAIL) !== false) {
                $query->where(
                    'visitor_email_hash',
                    hash_hmac('sha256', $needle, (string) config('app.key')),
                );
            } else {
                $query->where(
                    'visitor_id_hash',
                    hash_hmac('sha256', $filters['search'], (string) config('app.key')),
                );
            }
        }

        if ($filters['status'] !== '') {
            $query->where('status', $filters['status']);
        }

        match ($filters['outcome']) {
            'escalated' => $query->whereNotNull('escalated_at'),
            'resolved' => $query->whereNotNull('resolved_at'),
            'failed' => $query->whereHas('messages', fn ($message) => $message->where('status', MessageStatus::Failed)),
            'fallback' => $query->whereHas('messages', fn ($message) => $message->where('was_fallback', true)),
            default => null,
        };

        match ($filters['sort']) {
            'oldest' => $query->orderBy('created_at'),
            'escalated' => $query->orderByDesc('escalated_at'),
            'messages' => $query->orderByDesc('message_count'),
            default => $query->orderByDesc('created_at'),
        };

        return $query->paginate(10)->withQueryString();
    }

    /**
     * @return array{days: int, search: string, status: string, outcome: string, sort: string}
     */
    private function validatedFilters(Request $request): array
    {
        $validated = $request->validate([
            'days' => ['sometimes', 'integer', Rule::in([7, 14, 30, 90])],
            'search' => ['nullable', 'string', 'max:190'],
            'status' => ['nullable', Rule::in(['open', 'closed'])],
            'outcome' => ['nullable', Rule::in(['escalated', 'resolved', 'failed', 'fallback'])],
            'sort' => ['nullable', Rule::in(['newest', 'oldest', 'escalated', 'messages'])],
        ]);

        return [
            'days' => (int) ($validated['days'] ?? 30),
            'search' => trim((string) ($validated['search'] ?? '')),
            'status' => (string) ($validated['status'] ?? ''),
            'outcome' => (string) ($validated['outcome'] ?? ''),
            'sort' => (string) ($validated['sort'] ?? 'newest'),
        ];
    }

    private function since(array $filters): \DateTimeInterface
    {
        return now()->startOfDay()->subDays($filters['days'] - 1);
    }

    /**
     * Exports contain visitor data, so every download is written to the audit log.
     *
     * @param  array<string, mixed>  $filters
     */
    private function auditExport(Request $request, Site $site, array $filters): void
    {
        $details = ['days' => $filters['days']];

        if ($filters['search'] !== '') {
            // The raw term is searchable visitor data — only its hash is logged.
            $details['search_hash'] = hash_hmac('sha256', mb_strtolower($filters['search']), (string) config('app.key'));
        }

        WidgetConversationAuditLog::query()->create([
            'workspace_id' => $site->workspace_id,
            'site_id' => $site->getKey(),
            'actor_id' => $request->user()?->getKey(),
            'action' => 'analytics.exported',
            'details' => $details,
        ]);
    }
}
