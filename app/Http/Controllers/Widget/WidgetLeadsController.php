<?php

namespace App\Http\Controllers\Widget;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Models\User;
use App\Models\WidgetConversation;
use App\Models\WidgetConversationAuditLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class WidgetLeadsController extends Controller
{
    public function index(Request $request, Site $site): View|JsonResponse
    {
        Gate::authorize('viewLeads', $site);

        $filters = $this->validatedFilters($request);
        $conversations = $this->filteredQuery($site, $filters)
            ->with(['messages' => fn ($query) => $query->orderBy('id'), 'assignedUser'])
            ->orderBy(...$this->sort($filters))
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        $siteQuery = $this->siteQuery($site);
        $teamMembers = $this->teamMembers($site);
        $totals = [
            'conversations' => (clone $siteQuery)->count(),
            'with_email' => (clone $siteQuery)->whereNotNull('visitor_email_consent_at')->count(),
            'open' => (clone $siteQuery)->where('status', 'open')->count(),
            'follow_up' => (clone $siteQuery)->where('follow_up_status', 'pending')->count(),
            'leads' => (clone $siteQuery)->where('classification', 'lead')->count(),
        ];

        if ($request->expectsJson()) {
            return response()->json([
                'html' => view('widget.partials.lead-list', [
                    'site' => $site,
                    'conversations' => $conversations,
                    'teamMembers' => $teamMembers,
                ])->render(),
                'totals' => $totals,
                'updated_at' => now()->format('H:i:s T'),
            ])->header('Cache-Control', 'no-store, private');
        }

        return view('widget.leads', [
            'site' => $site,
            'conversations' => $conversations,
            'filters' => $filters,
            'teamMembers' => $teamMembers,
            'totals' => $totals,
        ]);
    }

    public function export(Request $request, Site $site): StreamedResponse
    {
        Gate::authorize('viewLeads', $site);

        $filters = $this->validatedFilters($request);
        $auditFilters = $filters;

        if (! empty($auditFilters['search'])) {
            $auditFilters['search_hash'] = hash_hmac('sha256', mb_strtolower($auditFilters['search']), (string) config('app.key'));
            unset($auditFilters['search']);
        }

        $this->audit($request, $site, null, 'visitor.exported', ['filters' => $auditFilters]);

        return response()->streamDownload(function () use ($filters, $site): void {
            $output = fopen('php://output', 'w');
            fputcsv($output, [
                'Visitor email', 'Conversation ID', 'Session ID', 'Started at', 'Website',
                'Status', 'Consent recorded at', 'Classification', 'Assigned to', 'Follow-up status', 'Transcript',
            ]);

            foreach ($this->filteredQuery($site, $filters)->with(['messages', 'assignedUser'])->orderBy('id')->lazyById(100) as $conversation) {
                $transcript = $conversation->messages
                    ->map(fn ($message): string => $message->role->value.': '.($message->safeContent() ?? 'Message preview unavailable.'))
                    ->implode("\n");

                fputcsv($output, [
                    $this->csvSafe((string) ($conversation->safeVisitorEmail() ?? '')),
                    $conversation->getKey(),
                    $this->csvSafe($conversation->safeVisitorId() ?? ''),
                    $conversation->created_at?->toIso8601String(),
                    $this->csvSafe($site->domain ?: $site->name),
                    $conversation->status,
                    $conversation->visitor_email_consent_at?->toIso8601String(),
                    $conversation->classification,
                    $this->csvSafe((string) $conversation->assignedUser?->name),
                    $conversation->follow_up_status,
                    $this->csvSafe($transcript),
                ]);
            }

            fclose($output);
        }, Str::slug($site->name).'-visitor-data.csv', [
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function update(Request $request, Site $site, WidgetConversation $conversation): RedirectResponse
    {
        Gate::authorize('viewLeads', $site);
        $this->ensureConversationBelongsToSite($site, $conversation);

        $validated = $request->validate([
            'status' => ['required', Rule::in(['open', 'closed'])],
            'classification' => ['required', Rule::in(['lead', 'support'])],
            'follow_up_status' => ['required', Rule::in(['none', 'pending', 'contacted', 'complete'])],
            'assigned_user_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $assignedUserId = $validated['assigned_user_id'] ?? null;

        if ($assignedUserId !== null && ! $this->teamMembers($site)->contains('id', $assignedUserId)) {
            throw ValidationException::withMessages([
                'assigned_user_id' => 'Choose a team member from this workspace.',
            ]);
        }

        $before = [
            'status' => $conversation->status,
            'classification' => $conversation->classification,
            'assigned_user_id' => $conversation->assigned_user_id,
            'follow_up_status' => $conversation->follow_up_status,
        ];

        $conversation->forceFill([
            ...$validated,
            'assigned_user_id' => $assignedUserId,
            'resolved_at' => $validated['status'] === 'closed'
                ? ($conversation->resolved_at ?? now())
                : null,
        ])->save();

        $this->audit($request, $site, $conversation, 'visitor.updated', [
            'before' => $before,
            'after' => [
                'status' => $conversation->status,
                'classification' => $conversation->classification,
                'assigned_user_id' => $conversation->assigned_user_id,
                'follow_up_status' => $conversation->follow_up_status,
            ],
        ]);

        return back()->with('status', 'Visitor conversation updated.');
    }

    /**
     * Hand an open conversation to a human — used by the analytics dashboard
     * when a visitor question failed or needs a person.
     */
    public function escalate(Request $request, Site $site, WidgetConversation $conversation): RedirectResponse
    {
        Gate::authorize('viewLeads', $site);
        $this->ensureConversationBelongsToSite($site, $conversation);

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:64'],
        ]);

        if ($conversation->escalated_at === null) {
            $conversation->forceFill([
                'escalated_at' => now(),
                'escalation_reason' => $validated['reason'] ?? 'manual',
            ])->save();

            $this->audit($request, $site, $conversation, 'visitor.escalated', [
                'reason' => $validated['reason'] ?? 'manual',
                'automated' => false,
            ]);
        }

        return back()->with('status', 'Conversation escalated to your team.');
    }

    /**
     * Mark a conversation finished: closes it, records the resolution time and
     * clears any open escalation so the funnel reflects the current state.
     */
    public function resolve(Request $request, Site $site, WidgetConversation $conversation): RedirectResponse
    {
        Gate::authorize('viewLeads', $site);
        $this->ensureConversationBelongsToSite($site, $conversation);

        $conversation->forceFill([
            'status' => 'closed',
            'resolved_at' => $conversation->resolved_at ?? now(),
            'escalated_at' => null,
            'escalation_reason' => null,
        ])->save();

        $this->audit($request, $site, $conversation, 'visitor.resolved', [
            'resolution_minutes' => $conversation->resolved_at?->diffInMinutes($conversation->created_at),
        ]);

        return back()->with('status', 'Conversation marked as resolved.');
    }

    public function destroy(Request $request, Site $site, WidgetConversation $conversation): RedirectResponse
    {
        Gate::authorize('viewLeads', $site);
        $this->ensureConversationBelongsToSite($site, $conversation);

        $details = [
            'conversation_id' => $conversation->getKey(),
        ];
        $visitorId = $conversation->safeVisitorId();

        if ($visitorId !== null) {
            $details['session_id_hash'] = hash_hmac('sha256', $visitorId, (string) config('app.key'));
        }

        $this->audit($request, $site, $conversation, 'visitor.deleted', $details);

        $conversation->delete();

        return back()->with('status', 'Visitor data and transcript deleted.');
    }

    /** @return array<string, string> */
    private function validatedFilters(Request $request): array
    {
        return $request->validate([
            'search' => ['nullable', 'string', 'max:190'],
            'status' => ['nullable', Rule::in(['open', 'closed'])],
            'classification' => ['nullable', Rule::in(['lead', 'support'])],
            'follow_up_status' => ['nullable', Rule::in(['none', 'pending', 'contacted', 'complete'])],
            'sort' => ['nullable', Rule::in(['newest', 'oldest', 'status', 'classification', 'follow_up_status'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
        ]);
    }

    /** @param array<string, string> $filters
     * @return Builder<WidgetConversation>
     */
    private function filteredQuery(Site $site, array $filters): Builder
    {
        $query = $this->siteQuery($site);
        $search = trim((string) ($filters['search'] ?? ''));

        if ($search !== '') {
            if (filter_var($search, FILTER_VALIDATE_EMAIL) !== false) {
                $query->where('visitor_email_hash', hash_hmac('sha256', mb_strtolower($search), (string) config('app.key')));
            } else {
                $query->where('visitor_id_hash', hash_hmac('sha256', $search, (string) config('app.key')));
            }
        }

        foreach (['status', 'classification', 'follow_up_status'] as $filter) {
            if (! empty($filters[$filter])) {
                $query->where($filter, $filters[$filter]);
            }
        }

        return $query;
    }

    /** @return Builder<WidgetConversation> */
    private function siteQuery(Site $site): Builder
    {
        return WidgetConversation::query()
            ->where('site_id', $site->getKey())
            ->when(
                $site->workspace_id === null,
                fn (Builder $query) => $query->whereNull('workspace_id'),
                fn (Builder $query) => $query->where('workspace_id', $site->workspace_id),
            );
    }

    /** @param array<string, string> $filters
     * @return array{0: string, 1: string}
     */
    private function sort(array $filters): array
    {
        $sort = $filters['sort'] ?? 'newest';
        $direction = $filters['direction'] ?? 'desc';

        return match ($sort) {
            'oldest' => ['created_at', 'asc'],
            'status', 'classification', 'follow_up_status' => [$sort, $direction],
            default => ['created_at', 'desc'],
        };
    }

    /** @return Collection<int, User> */
    private function teamMembers(Site $site): Collection
    {
        $workspace = $site->workspace;

        if ($workspace === null) {
            return User::query()->whereKey($site->user_id)->get(['id', 'name', 'email']);
        }

        return $workspace->members()
            ->wherePivotIn('role', ['owner', 'admin', 'support'])
            ->orderBy('name')
            ->get(['users.id', 'users.name', 'users.email']);
    }

    private function ensureConversationBelongsToSite(Site $site, WidgetConversation $conversation): void
    {
        abort_unless(
            $conversation->site_id === $site->getKey()
                && $conversation->workspace_id === $site->workspace_id,
            404,
        );
    }

    /** @param array<string, mixed> $details */
    private function audit(
        Request $request,
        Site $site,
        ?WidgetConversation $conversation,
        string $action,
        array $details,
    ): void {
        WidgetConversationAuditLog::query()->create([
            'workspace_id' => $site->workspace_id,
            'site_id' => $site->getKey(),
            'conversation_id' => $conversation?->getKey(),
            'actor_id' => $request->user()->getKey(),
            'action' => $action,
            'details' => $details,
            'created_at' => now(),
        ]);
    }

    private function csvSafe(string $value): string
    {
        return preg_match('/^[=+\-@\t\r]/', $value) === 1 ? "'".$value : $value;
    }
}
