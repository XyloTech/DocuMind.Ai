<?php

namespace App\Http\Controllers;

use App\Services\Privacy\ConsentService;
use App\Services\Privacy\PrivacyAudit;
use App\Services\Privacy\PrivacyDataService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The privacy centre: consent, retention, export, deletion and the audit trail
 * behind them. Every action only ever touches the signed-in account — there is
 * no identifier in the route to leak someone else's data through.
 */
class PrivacyController extends Controller
{
    public function __construct(
        private readonly ConsentService $consent,
        private readonly PrivacyDataService $data,
        private readonly PrivacyAudit $audit,
    ) {}

    /**
     * Settings page with the toggles, retention window, export/delete and the
     * account's own audit trail.
     */
    public function index(Request $request): View
    {
        $user = $request->user();

        return view('privacy.settings', [
            'user' => $user,
            'retentionOptions' => (array) config('privacy.retention_options'),
            'activity' => $user->auditLogs()->limit(20)->get(),
            'documentCount' => $user->documents()->count(),
            'conversationCount' => $user->chats()->count(),
        ]);
    }

    /**
     * Public privacy notice, readable before an account even exists.
     */
    public function policy(): View
    {
        return view('privacy.policy');
    }

    /**
     * First-run consent prompt.
     */
    public function consent(Request $request): RedirectResponse|JsonResponse
    {
        $validated = $request->validate([
            'store_chat_history' => ['sometimes', 'boolean'],
            'allow_model_training' => ['sometimes', 'boolean'],
        ]);

        $this->consent->record(
            $request->user(),
            [
                'store_chat_history' => (bool) ($validated['store_chat_history'] ?? false),
                'allow_model_training' => (bool) ($validated['allow_model_training'] ?? false),
            ],
            $request->ip(),
        );

        if ($request->expectsJson()) {
            return response()->json(['consented' => true]);
        }

        return back()->with('status', 'Privacy preferences saved. You can change them any time.');
    }

    /**
     * Change the toggles or the retention window from the settings page.
     */
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'store_chat_history' => ['sometimes', 'boolean'],
            'allow_model_training' => ['sometimes', 'boolean'],
            'chat_retention_days' => [
                'nullable',
                'integer',
                Rule::in((array) config('privacy.retention_options')),
            ],
        ], [
            'chat_retention_days.in' => 'Choose one of the offered retention windows.',
        ]);

        $this->consent->update($request->user(), $validated, $request->ip());

        return back()->with('status', 'Privacy settings updated.');
    }

    /**
     * Withdraw consent: storage and training stop, and the prompt returns.
     */
    public function revoke(Request $request): RedirectResponse
    {
        $this->consent->revoke($request->user(), $request->ip());

        return redirect()
            ->route('settings.privacy')
            ->with('status', 'Consent withdrawn. Nothing new is stored until you opt in again.');
    }

    /**
     * Download everything stored against the account.
     */
    public function export(Request $request): StreamedResponse
    {
        $user = $request->user();

        $payload = $this->data->export($user);

        $this->audit->record(
            $user,
            'data.exported',
            'Exported '.count($payload['conversations']).' conversations and '.count($payload['documents']).' documents.',
            [
                'conversations' => count($payload['conversations']),
                'documents' => count($payload['documents']),
            ],
            $request->ip(),
        );

        return response()->streamDownload(function () use ($payload): void {
            echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }, 'documind-export-'.now()->format('Y-m-d').'.json', [
            'Content-Type' => 'application/json; charset=utf-8',
        ]);
    }

    /**
     * Permanently delete stored content. Requires the password and a typed
     * confirmation so a borrowed session cannot wipe an account.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->validate([
            'confirmation' => ['required', Rule::in(['DELETE'])],
        ], [
            'confirmation.required' => 'Type DELETE to confirm.',
            'confirmation.in' => 'Type DELETE to confirm.',
        ]);

        if ((int) $request->session()->get('firebase_authenticated_at', 0) < now()->subMinutes(10)->getTimestamp()) {
            return back()->withErrors([
                'confirmation' => 'For your security, sign out and sign in with Google again before deleting data.',
            ]);
        }

        $counts = $this->data->erase($request->user(), $request->ip());

        return redirect()
            ->route('dashboard')
            ->with('status', 'Stored data deleted: '.$counts['conversations'].' conversations, '.$counts['messages'].' messages and '.$counts['documents'].' documents.');
    }
}
