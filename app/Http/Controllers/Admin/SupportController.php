<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SupportConversation;
use App\Services\AdminAudit;
use App\Services\Support\SupportInbox;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * The staff side of the human-support channel: read the full transcript,
 * answer it and close it out. Reachable only through the routes gated by
 * the `view-admin-support-data` ability (administrators and support).
 */
class SupportController extends Controller
{
    public function __construct(
        private readonly SupportInbox $inbox,
        private readonly AdminAudit $audit,
    ) {}

    public function show(Request $request, SupportConversation $supportConversation): View
    {
        Gate::authorize('view', $supportConversation);

        $supportConversation->loadMissing('widgetConversation.site:id,name');

        return view('admin.support', [
            'conversation' => $supportConversation,
            'messages' => $supportConversation->messages()->with('sender:id,name')->orderBy('id')->get(),
        ]);
    }

    public function reply(Request $request, SupportConversation $supportConversation): RedirectResponse
    {
        Gate::authorize('update', $supportConversation);

        abort_unless(
            $supportConversation->status->isLive(),
            409,
            'This conversation has already been resolved.',
        );

        $validated = $request->validate([
            'message' => ['required', 'string', 'max:4000'],
        ], [
            'message.required' => 'Type a reply first.',
            'message.max' => 'Keep the reply under 4,000 characters.',
        ]);

        $message = trim($validated['message']);

        if ($message === '') {
            abort(422, 'Type a reply first.');
        }

        $this->inbox->reply($supportConversation, $request->user(), $message);

        $this->audit->record(
            $request->user(),
            $supportConversation->user,
            'support.replied',
            'Replied in support conversation #'.$supportConversation->getKey(),
        );

        return back()->with('status', 'Reply sent to the visitor.');
    }

    public function resolve(Request $request, SupportConversation $supportConversation): RedirectResponse
    {
        Gate::authorize('update', $supportConversation);

        $this->inbox->resolve($supportConversation, $request->user());

        $this->audit->record(
            $request->user(),
            $supportConversation->user,
            'support.resolved',
            'Resolved support conversation #'.$supportConversation->getKey(),
        );

        return back()->with('status', 'Conversation resolved.');
    }
}
