<?php

namespace App\Http\Controllers\Admin;

use App\Enums\NotificationType;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use App\Models\Chat;
use App\Models\Document;
use App\Models\Site;
use App\Models\SupportConversation;
use App\Models\User;
use App\Models\WidgetConversation;
use App\Services\AdminAudit;
use App\Services\Notifier;
use App\Services\Settings;
use App\Support\ModelBrand;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DashboardController extends Controller
{
    public function __construct(
        private readonly Settings $settings,
        private readonly AdminAudit $audit,
    ) {}

    public function __invoke(Request $request): View
    {
        $actor = $request->user();
        $activeTab = (string) $request->query('tab', 'overview');
        $tabs = ['overview', 'accounts', 'knowledge', 'conversations', 'support', 'widgets', 'audit', 'models'];

        abort_unless(in_array($activeTab, $tabs, true), 404);

        $canManage = $actor->canManagePlatform();
        $canViewAccounts = $actor->can('view-admin-accounts');
        $canViewSupportData = $actor->can('view-admin-support-data');
        $accounts = null;
        $knowledge = null;
        $chats = null;
        $supportConversations = null;
        $widgetConversations = null;
        $sites = null;
        $auditLogs = null;
        $search = trim((string) $request->query('search', ''));
        $roleFilter = (string) $request->query('role', '');
        $statusFilter = (string) $request->query('status', '');
        $modelDriver = $this->activeAiDriver();

        $stats = [
            ['label' => 'Customer accounts', 'value' => User::query()->where('role', UserRole::User)->count()],
            ['label' => 'Staff accounts', 'value' => User::query()->whereIn('role', [UserRole::Admin, UserRole::Support, UserRole::Analyst])->count()],
            ['label' => 'Suspended accounts', 'value' => User::query()->where('is_banned', true)->count()],
            ['label' => 'Knowledge sources', 'value' => Document::query()->count()],
            ['label' => 'Support chats', 'value' => Chat::query()->count()],
            ['label' => 'Human support requests', 'value' => SupportConversation::query()->count()],
            ['label' => 'Website assistants', 'value' => Site::query()->count()],
            ['label' => 'Widget conversations', 'value' => WidgetConversation::query()->count()],
            ['label' => 'Credits in circulation', 'value' => (int) User::query()->sum('credits')],
        ];

        if ($activeTab === 'accounts') {
            Gate::authorize('view-admin-accounts');

            $this->record($actor, null, 'accounts.viewed', 'Viewed account directory', [
                'search_used' => $search !== '',
                'search_mode' => $actor->isAdmin() ? 'name_or_email' : 'account_id',
                'role_filter' => $roleFilter,
                'status_filter' => $statusFilter,
            ]);

            $columns = $actor->isAdmin()
                ? ['id', 'name', 'email', 'role', 'is_banned', 'credits', 'created_at', 'last_login_at']
                : ['id', 'role', 'is_banned', 'created_at', 'last_login_at'];

            $query = User::query()->select($columns);

            if ($search !== '') {
                $query->where(function (Builder $builder) use ($search, $actor): void {
                    if ($actor->isAdmin()) {
                        $builder->where('name', 'like', '%'.$search.'%')
                            ->orWhere('email', 'like', '%'.$search.'%');
                    } elseif (ctype_digit($search)) {
                        $builder->whereKey((int) $search);
                    } else {
                        $builder->whereKey(0);
                    }
                });
            }

            if (in_array($roleFilter, $this->roleValues(), true)) {
                $query->where('role', $roleFilter);
            }

            if ($statusFilter === 'active') {
                $query->where('is_banned', false);
            } elseif ($statusFilter === 'suspended') {
                $query->where('is_banned', true);
            }

            $sortFields = $actor->isAdmin()
                ? ['name', 'credits', 'last_login_at']
                : ['last_login_at'];
            $sort = in_array($request->query('sort'), $sortFields, true)
                ? (string) $request->query('sort')
                : 'created_at';
            $direction = $request->query('direction') === 'asc' ? 'asc' : 'desc';

            $accounts = $query
                ->orderBy($sort, $direction)
                ->orderBy('id')
                ->paginate(20)
                ->withQueryString();

            $accounts->getCollection()->transform(function (User $user) use ($actor): User {
                $user->setAttribute('admin_display_name', $actor->isAdmin() ? $user->name : 'Account #'.$user->getKey());
                $user->setAttribute('admin_display_email', $actor->isAdmin() ? $user->email : 'Restricted');

                return $user;
            });
        } elseif ($activeTab === 'knowledge') {
            Gate::authorize('view-admin-support-data');
            $this->record($actor, null, 'knowledge.viewed', 'Viewed knowledge-source metadata');

            $knowledge = Document::query()
                ->with('user:id,name')
                ->select(['id', 'user_id', 'filename', 'status', 'size_bytes', 'chunk_count', 'created_at', 'processed_at'])
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->paginate(20)
                ->withQueryString();
        } elseif ($activeTab === 'conversations') {
            Gate::authorize('view-admin-support-data');
            $this->record($actor, null, 'conversations.metadata_viewed', 'Viewed conversation metadata without message content');

            $chats = Chat::query()
                ->with('user:id,name')
                ->select(['id', 'user_id', 'document_id', 'message_count', 'last_message_at', 'created_at'])
                ->withCount('messages')
                ->orderByDesc('last_message_at')
                ->orderByDesc('id')
                ->paginate(20, ['*'], 'chats_page')
                ->withQueryString();

            $widgetConversations = WidgetConversation::query()
                ->with('site:id,name,user_id')
                ->select(['id', 'site_id', 'message_count', 'created_at', 'updated_at'])
                ->withCount('messages')
                ->orderByDesc('updated_at')
                ->orderByDesc('id')
                ->paginate(20, ['*'], 'widget_page')
                ->withQueryString();
        } elseif ($activeTab === 'support') {
            Gate::authorize('view-admin-support-data');
            $this->audit->record($actor, null, 'support.inbox_viewed', 'Viewed the human support inbox');

            $supportConversations = SupportConversation::query()
                ->with(['user:id,name', 'agent:id,name', 'widgetConversation.site:id,name'])
                ->withCount('messages')
                ->orderByDesc('last_message_at')
                ->orderByDesc('id')
                ->paginate(20, ['*'], 'support_page')
                ->withQueryString();
        } elseif ($activeTab === 'widgets') {
            Gate::authorize('view-admin-support-data');
            $this->record($actor, null, 'widgets.viewed', 'Viewed website assistant configuration metadata');

            $sites = Site::query()
                ->with('user:id,name')
                ->select(['id', 'user_id', 'name', 'bot_name', 'domain', 'enabled', 'monthly_quota', 'messages_used', 'created_at'])
                ->withCount(['documents', 'conversations'])
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->paginate(20)
                ->withQueryString();
        } elseif ($activeTab === 'audit') {
            Gate::authorize('view-admin-audit');
            $auditLogs = AdminAuditLog::query()
                ->with(['actor:id,name', 'subject:id,name'])
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->paginate(30)
                ->withQueryString();
        } elseif ($activeTab === 'models') {
            Gate::authorize('manage-admin');
        }

        return view('admin.dashboard', [
            'activeTab' => $activeTab,
            'accounts' => $accounts,
            'auditLogs' => $auditLogs,
            'canManage' => $canManage,
            'canViewAccounts' => $canViewAccounts,
            'canViewSupportData' => $canViewSupportData,
            'chats' => $chats,
            'knowledge' => $knowledge,
            'modelDriver' => $modelDriver,
            'modelName' => ModelBrand::active(),
            'openAiKeyConfigured' => $this->settings->getString('openai_api_key', '') !== '' || (string) config('openai.api_key') !== '',
            'roleOptions' => UserRole::cases(),
            'roleFilter' => $roleFilter,
            'search' => $search,
            'sites' => $sites,
            'stats' => $stats,
            'statusFilter' => $statusFilter,
            'supportConversations' => $supportConversations,
            'widgetConversations' => $widgetConversations,
        ]);
    }

    public function storeUser(Request $request): RedirectResponse
    {
        Gate::authorize('manage-admin');

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:users,email'],
            'role' => ['required', Rule::in($this->roleValues())],
            'credits' => ['required', 'integer', 'min:0', 'max:1000000'],
            'reason' => ['required', 'string', 'min:8', 'max:255'],
        ]);

        $user = DB::transaction(function () use ($request, $validated): User {
            $user = User::query()->create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => Str::random(64),
            ]);
            $user->forceFill([
                'role' => UserRole::from($validated['role']),
                'credits' => (int) $validated['credits'],
                'is_banned' => false,
            ])->save();

            $this->record($request->user(), $user, 'account.created', 'Created account', [
                'reason' => Str::squish($validated['reason']),
                'role' => $validated['role'],
                'credits' => (int) $validated['credits'],
            ]);

            return $user;
        });

        return back()->with('status', 'Account created. The user can continue with Google using this verified email.');
    }

    public function updateUser(Request $request, User $user): RedirectResponse
    {
        Gate::authorize('manage-admin');
        abort_if($user->is($request->user()), 403, 'Use account settings to manage your own access.');

        $validated = $request->validate([
            'role' => ['required', Rule::in($this->roleValues())],
            'status' => ['required', Rule::in(['active', 'suspended'])],
            'credits' => ['required', 'integer', 'min:0', 'max:1000000'],
            'reason' => ['required', 'string', 'min:8', 'max:255'],
        ]);

        $roleBefore = $user->role->value;
        $bannedBefore = (bool) $user->is_banned;
        $creditsBefore = (int) $user->credits;

        DB::transaction(function () use ($request, $user, $validated): void {
            $locked = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
            $nextRole = UserRole::from($validated['role']);
            $nextBanned = $validated['status'] === 'suspended';

            if ($locked->isAdmin() && ($nextRole !== UserRole::Admin || $nextBanned)
                && User::query()->where('role', UserRole::Admin)->where('is_banned', false)->count() <= 1) {
                abort(422, 'At least one active administrator must remain.');
            }

            $before = [
                'role' => $locked->role->value,
                'status' => $locked->is_banned ? 'suspended' : 'active',
                'credits' => $locked->credits,
            ];

            $locked->forceFill([
                'role' => $nextRole,
                'is_banned' => $nextBanned,
                'credits' => (int) $validated['credits'],
            ])->save();

            $this->record($request->user(), $locked, 'account.updated', 'Updated account access or credits', [
                'reason' => Str::squish($validated['reason']),
                'before' => $before,
                'after' => [
                    'role' => $nextRole->value,
                    'status' => $nextBanned ? 'suspended' : 'active',
                    'credits' => (int) $validated['credits'],
                ],
            ]);
        });

        $user->refresh();

        $changes = [];

        if ($roleBefore !== $user->role->value) {
            $changes[] = 'role → '.$user->role->label();
        }

        if ($bannedBefore !== (bool) $user->is_banned) {
            $changes[] = 'status → '.($user->is_banned ? 'suspended' : 'active');
        }

        $creditsChanged = $creditsBefore !== (int) $user->credits;

        if ($creditsChanged) {
            $changes[] = 'credits → '.$user->credits;
        }

        if ($changes !== []) {
            Notifier::make()
                ->type(NotificationType::AdminAction)
                ->to($user)
                ->title('Your account was updated')
                ->body(implode(', ', $changes).'. Reason: '.Str::squish($validated['reason']))
                ->meta(['reason' => Str::squish($validated['reason'])])
                ->send();
        }

        if ($creditsChanged) {
            Notifier::make()
                ->type(NotificationType::CreditsChanged)
                ->to($user)
                ->title('Credits updated')
                ->body('Your balance changed from '.$creditsBefore.' to '.$user->credits.' credits.')
                ->send();
        }

        return back()->with('status', 'Account changes saved and logged.');
    }

    public function updateModelSettings(Request $request): RedirectResponse
    {
        Gate::authorize('manage-admin');

        $validated = $request->validate([
            'ai_driver' => ['required', Rule::in(['local', 'openai', 'fake'])],
            'openai_api_key' => ['nullable', 'string', 'max:500'],
            'reason' => ['required', 'string', 'min:8', 'max:255'],
        ]);

        $apiKey = trim((string) ($validated['openai_api_key'] ?? ''));
        $hasKey = $apiKey !== '';
        $existingKey = $this->settings->getString('openai_api_key', (string) config('openai.api_key'));

        if ($validated['ai_driver'] === 'openai' && ! $hasKey && $existingKey === '') {
            return back()->withErrors(['openai_api_key' => 'Add an API key before selecting the OpenAI driver.']);
        }

        DB::transaction(function () use ($request, $validated, $hasKey, $apiKey): void {
            $this->settings->set('ai_driver', $validated['ai_driver'], 'ai');

            if ($hasKey) {
                $this->settings->set('openai_api_key', $apiKey, 'ai', true);
            }

            $this->record($request->user(), null, 'model.access_updated', 'Updated model access settings', [
                'reason' => Str::squish($validated['reason']),
                'driver' => $validated['ai_driver'],
                'api_key_updated' => $hasKey,
            ]);
        });

        return back()->with('status', 'Model access settings saved. API keys are encrypted and never shown.');
    }

    public function exportUsers(Request $request): StreamedResponse
    {
        Gate::authorize('manage-admin');
        $this->record($request->user(), null, 'accounts.exported', 'Exported masked account directory', [
            'reason' => 'Operational export; email addresses are masked.',
        ]);

        return response()->streamDownload(function (): void {
            $output = fopen('php://output', 'w');
            fputcsv($output, ['Account ID', 'Name', 'Masked email', 'Role', 'Status', 'Credits', 'Created at']);

            User::query()
                ->select(['id', 'name', 'email', 'role', 'is_banned', 'credits', 'created_at'])
                ->orderBy('id')
                ->chunkById(500, function ($users) use ($output): void {
                    foreach ($users as $user) {
                        fputcsv($output, [
                            $user->getKey(),
                            $this->csvSafe($user->name),
                            $this->csvSafe($this->maskEmail($user->email)),
                            $user->role->label(),
                            $user->is_banned ? 'suspended' : 'active',
                            $user->credits,
                            $user->created_at?->toIso8601String(),
                        ]);
                    }
                });

            fclose($output);
        }, 'accounts-'.now()->format('Y-m-d').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    /** @return list<string> */
    private function roleValues(): array
    {
        return array_map(static fn (UserRole $role): string => $role->value, UserRole::cases());
    }

    private function activeAiDriver(): string
    {
        $saved = $this->settings->getString('ai_driver', '');

        if (in_array($saved, ['local', 'openai', 'fake'], true)) {
            return $saved;
        }

        $configured = (string) config('rag.ai_driver', '');

        if (in_array($configured, ['local', 'openai', 'fake'], true)) {
            return $configured;
        }

        if ((bool) config('ml.enabled', true)) {
            return 'local';
        }

        return $this->settings->getString('openai_api_key', (string) config('openai.api_key')) !== ''
            ? 'openai'
            : 'fake';
    }

    private function record(User $actor, ?User $subject, string $action, string $summary, array $details = []): void
    {
        $this->audit->record($actor, $subject, $action, $summary, $details);
    }

    private function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($local, 0, 1).'***@'.$domain;
    }

    private function csvSafe(string $value): string
    {
        return preg_match('/^[=+\-@\t\r]/', $value) === 1 ? "'".$value : $value;
    }
}
