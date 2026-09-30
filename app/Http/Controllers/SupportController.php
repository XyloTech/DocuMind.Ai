<?php

namespace App\Http\Controllers;

use App\Enums\SupportMessageRole;
use App\Models\SupportConversation;
use App\Models\SupportMessage;
use App\Services\Support\SupportInbox;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * The customer side of the human-support channel: the page itself, the
 * composer that opens or continues a conversation, the poll the page runs
 * while it is open, and the close action.
 */
class SupportController extends Controller
{
    public function __construct(private readonly SupportInbox $inbox) {}

    public function index(Request $request): View
    {
        $conversation = $this->current($request);

        return view('support.index', [
            'conversation' => $conversation,
            'messages' => $conversation?->messages()->with('sender:id,name')->orderBy('id')->get() ?? collect(),
        ]);
    }

    /**
     * Send a message: opens the conversation on the first send, appends to
     * the live one afterwards.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'message' => ['required', 'string', 'max:4000'],
            'since' => ['nullable', 'integer', 'min:0'],
        ], [
            'message.required' => 'Type a message first.',
            'message.max' => 'That message is too long. Keep it under 4,000 characters.',
        ]);

        $message = trim($validated['message']);

        if ($message === '') {
            throw ValidationException::withMessages(['message' => 'Type a message first.']);
        }

        $conversation = $this->inbox->start($request->user(), null, $message);

        return response()->json($this->payload(
            $conversation->fresh(),
            (int) ($validated['since'] ?? 0),
        ));
    }

    /**
     * New messages and the current status, polled while the page is open.
     */
    public function poll(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'since' => ['nullable', 'integer', 'min:0'],
        ]);

        return response()->json($this->payload(
            $this->current($request),
            (int) ($validated['since'] ?? 0),
        ));
    }

    public function close(Request $request): JsonResponse
    {
        $conversation = $this->current($request);

        abort_if($conversation === null, 404, 'No support conversation to close.');

        Gate::authorize('close', $conversation);

        $this->inbox->resolve($conversation, $request->user());

        return response()->json($this->payload($conversation->fresh(), 0));
    }

    /**
     * The signed-in customer's most recent conversation. Staff read the same
     * records through the admin inbox, never through these routes.
     */
    private function current(Request $request): ?SupportConversation
    {
        return SupportConversation::query()
            ->where('user_id', $request->user()->getKey())
            ->with(['agent:id,name'])
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @return array{conversation: array<string, mixed>|null, messages: list<array<string, mixed>>}
     */
    private function payload(?SupportConversation $conversation, int $since): array
    {
        if ($conversation === null) {
            return ['conversation' => null, 'messages' => []];
        }

        return [
            'conversation' => [
                'id' => $conversation->getKey(),
                'status' => $conversation->status->value,
                'status_label' => $conversation->status->label(),
                'agent' => $conversation->agent?->name,
                'live' => $conversation->status->isLive(),
                'message_count' => (int) $conversation->message_count,
                'last_message_at' => $conversation->last_message_at?->toIso8601String(),
            ],
            'messages' => $conversation->messages()
                ->with('sender:id,name')
                ->where('id', '>', $since)
                ->orderBy('id')
                ->get()
                ->map(fn (SupportMessage $message): array => [
                    'id' => $message->getKey(),
                    'role' => $message->role->value,
                    'label' => $message->role === SupportMessageRole::Agent
                        ? ($message->sender?->name ?? SupportMessageRole::Agent->label())
                        : $message->role->label(),
                    'content' => $message->content,
                    'created_at' => $message->created_at?->toIso8601String(),
                ])
                ->values()
                ->all(),
        ];
    }
}
