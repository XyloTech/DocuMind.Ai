<?php

namespace App\Http\Controllers\Widget;

use App\Enums\DocumentStatus;
use App\Enums\NotificationType;
use App\Enums\WidgetPosition;
use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\Site;
use App\Services\Notifier;
use App\Support\EmbedSnippet;
use App\Support\WidgetBundle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Owner-facing management of embeddable sites.
 */
class SiteController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();

        $workspaceId = $request->session()->get('active_workspace_id');

        $sites = Site::query()
            ->where('user_id', $user->getKey())
            ->when($workspaceId !== null, fn ($query) => $query->where('workspace_id', $workspaceId))
            ->with('documents:id,filename,status')
            ->withCount('conversations')
            ->orderByDesc('created_at')
            ->get();

        return view('widget.index', [
            'sites' => $sites,
            'documents' => Document::query()
                ->where('user_id', $user->getKey())
                ->when($workspaceId !== null, fn ($query) => $query->where('workspace_id', $workspaceId))
                ->where('status', DocumentStatus::Processed)
                ->orderByDesc('created_at')
                ->get(['id', 'filename', 'page_count', 'chunk_count', 'created_at']),
            'positions' => WidgetPosition::values(),
        ]);
    }

    public function store(Request $request): JsonResponse|RedirectResponse
    {
        Gate::authorize('create', Site::class);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'domain' => ['nullable', 'string', 'max:190'],
            'monthly_quota' => ['nullable', 'integer', 'min:0', 'max:1000000'],
        ]);

        $site = Site::create([
            'user_id' => $request->user()->getKey(),
            'workspace_id' => $request->session()->get('active_workspace_id'),
            'name' => $validated['name'],
            'domain' => $validated['domain'] ?? null,
            'monthly_quota' => (int) ($validated['monthly_quota'] ?? 1000),
            'quota_started_at' => now()->startOfMonth(),
        ]);

        if ($request->expectsJson()) {
            return response()->json(['site' => $site], 201);
        }

        return redirect()
            ->route('widget.show', $site)
            ->withFragment('install-embed-code')
            ->with('status', '“'.$site->name.'” is ready. Copy the install snippet into your website.');
    }

    public function show(Request $request, Site $site): View
    {
        Gate::authorize('view', $site);

        $site->rotateQuotaIfNeeded();

        $embed = EmbedSnippet::for($site, $this->origin($request));

        return view('widget.show', [
            'site' => $site,
            'documents' => Document::query()
                ->where('user_id', $request->user()->getKey())
                ->when($request->session()->get('active_workspace_id') !== null, fn ($query) => $query->where('workspace_id', $request->session()->get('active_workspace_id')))
                ->where('status', DocumentStatus::Processed)
                ->orderByDesc('created_at')
                ->get(['id', 'filename', 'page_count', 'chunk_count', 'created_at']),
            'positions' => WidgetPosition::values(),
            'snippet' => $embed->script($site),
            'snippets' => $embed->forSite($site),
            'origin' => $embed->origin(),
            // A stale build is the single most common reason a pasted snippet
            // silently does nothing, so surface it instead of hiding it.
            'widgetBuilt' => WidgetBundle::isBuilt(),
        ]);
    }

    public function update(Request $request, Site $site): RedirectResponse
    {
        Gate::authorize('update', $site);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'domain' => ['nullable', 'string', 'max:190'],
            'bot_name' => ['required', 'string', 'max:60'],
            'greeting' => ['nullable', 'string', 'max:255'],
            'accent_color' => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'logo_url' => ['nullable', 'url', 'max:255'],
            'theme' => ['sometimes', Rule::in(['dark', 'light', 'system'])],
            'launcher_icon' => ['sometimes', Rule::in(['brand', 'chat', 'spark', 'support'])],
            'launcher_label' => ['sometimes', 'string', 'min:1', 'max:40'],
            'launcher_animation' => ['sometimes', Rule::in(['pulse', 'bounce', 'fade', 'none'])],
            'blobatar_seed' => ['nullable', 'string', 'min:1', 'max:64'],
            'blobatar_size' => ['sometimes', 'integer', 'min:24', 'max:64'],
            'blobatar_hue' => ['nullable', 'integer', 'min:0', 'max:360'],
            'blobatar_tone' => ['nullable', 'numeric', 'min:0', 'max:0.999'],
            'blobatar_background' => ['sometimes', Rule::in(Site::BACKGROUNDS)],
            'blobatar_expression' => ['sometimes', Rule::in(Site::EXPRESSIONS)],
            'blobatar_animation' => ['sometimes', Rule::in(Site::ANIMATIONS)],
            'position' => ['required', Rule::in(WidgetPosition::values())],
            'monthly_quota' => ['required', 'integer', 'min:0', 'max:1000000'],
            'collect_email' => ['nullable', 'boolean'],
            'visitor_retention_days' => ['sometimes', 'integer', Rule::in([30, 90, 180, 365])],
            'enabled' => ['nullable', 'boolean'],
            'documents' => ['nullable', 'array'],
            'documents.*' => ['integer'],
        ]);

        $site->update([
            ...$validated,
            'accent_color' => strtolower($validated['accent_color']),
            'collect_email' => (bool) ($validated['collect_email'] ?? false),
            'enabled' => (bool) ($validated['enabled'] ?? false),
            // Cleared controls come through as missing keys, and "derive it
            // from the assistant's name" is null — so an absent key writes
            // null rather than leaving the previous pin in place.
            'blobatar_seed' => $validated['blobatar_seed'] ?? null,
            'blobatar_hue' => $validated['blobatar_hue'] ?? null,
            'blobatar_tone' => $validated['blobatar_tone'] ?? null,
        ]);

        // Only documents the owner actually owns may be linked to their site.
        $allowed = Document::query()
            ->where('user_id', $request->user()->getKey())
            ->when($request->session()->get('active_workspace_id') !== null, fn ($query) => $query->where('workspace_id', $request->session()->get('active_workspace_id')))
            ->whereIn('id', $validated['documents'] ?? [])
            ->pluck('id');

        $site->documents()->sync($allowed);

        Notifier::make()
            ->type(NotificationType::WidgetUpdated)
            ->toWorkspace($site->workspace_id, $request->user())
            ->title('Widget settings updated')
            ->body($request->user()->name.' updated the settings for '.$site->name.'.')
            ->link(route('widget.show', $site), 'Open site')
            ->send();

        return back()->with('status', 'Widget settings saved.');
    }

    public function destroy(Request $request, Site $site): RedirectResponse
    {
        Gate::authorize('delete', $site);

        $site->delete();

        return redirect()
            ->route('widget.index')
            ->with('status', 'Site removed. Its embed code will stop responding.');
    }

    public function rotateKey(Request $request, Site $site): RedirectResponse
    {
        Gate::authorize('update', $site);

        $site->update(['site_key' => Site::generateKey()]);

        Notifier::make()
            ->type(NotificationType::WidgetKeyRotated)
            ->toWorkspace($site->workspace_id, $request->user())
            ->title('Site key rotated')
            ->body($request->user()->name.' rotated the key for '.$site->name.'. Existing embed snippets will stop working until updated.')
            ->link(route('widget.show', $site), 'Get new snippet')
            ->send();

        return back()->with('status', 'A new site key was generated. Update your embed snippet.');
    }

    public function toggle(Request $request, Site $site): JsonResponse
    {
        Gate::authorize('update', $site);

        $site->update(['enabled' => ! $site->enabled]);

        Notifier::make()
            ->type(NotificationType::WidgetUpdated)
            ->toWorkspace($site->workspace_id, $request->user())
            ->title($site->enabled ? 'Widget enabled' : 'Widget paused')
            ->body($request->user()->name.' '.($site->enabled ? 'enabled' : 'paused').' the widget on '.$site->name.'.')
            ->link(route('widget.show', $site), 'Open site')
            ->send();

        return response()->json(['enabled' => $site->enabled]);
    }

    /**
     * A sandbox page that loads the real widget exactly the way a customer's
     * site would. It is the only way to honestly confirm that installation
     * works before pasting the snippet into production.
     */
    public function preview(Request $request, Site $site): View
    {
        Gate::authorize('view', $site);

        $origin = $this->origin($request);

        return view('widget.preview', [
            'site' => $site,
            // Fingerprinted so this page can never be shown a bundle the
            // browser is still holding from before a rebuild — the plain URL
            // stays cacheable for installed snippets, but a sandbox whose whole
            // job is to prove the current build works must load the current
            // build.
            'scriptUrl' => $origin.'/widget.js?v='.WidgetBundle::version(),
            'siteKey' => $site->site_key,
            'isLive' => $site->isLive(),
        ]);
    }

    /**
     * The origin the customer's site should load the widget from.
     *
     * `config('app.url')` is whatever was set in .env, which in local and
     * staging environments is usually `http://localhost` — pasting that snippet
     * onto a real site loads nothing at all. The host the owner is actually
     * browsing is the one their visitors can reach.
     */
    private function origin(Request $request): string
    {
        return $request->getSchemeAndHttpHost();
    }
}
