<?php

namespace App\Services\Support;

use App\Enums\NotificationType;
use App\Enums\SupportMessageRole;
use App\Enums\SupportStatus;
use App\Enums\UserRole;
use App\Models\SupportConversation;
use App\Models\SupportMessage;
use App\Models\User;
use App\Models\WidgetConversation;
use App\Services\Notifier;
use Illuminate\Support\Facades\DB;

/**
 * Everything that moves a support conversation forward: opening one,
 * assigning an agent, appending messages and closing the ticket.
 *
 * The service is the single writer of `message_count`, `last_message_at` and
 * the status transitions, so the widget, the support transcript and the admin
 * inbox can never disagree about what a conversation currently is. The
 * customer is an anonymous widget visitor (no `user_id`), so visitor-visible
 * state travels back to them through the widget's support polling rather
 * than notifications. Notifications are raised inside the same transaction
 * as the row they describe: a rolled-back reply never leaves a staff toast
 * behind.
 */
final class SupportInbox
{
    /**
     * Open a conversation for the widget visitor, or append to the live one
     * they already have. Returns the conversation the message landed in.
     */
    public function start(WidgetConversation $widgetConversation, string $message): SupportConversation
    {
        $result = DB::transaction(function () use ($widgetConversation, $message): array {
            $existing = SupportConversation::query()
                ->where('widget_conversation_id', $widgetConversation->getKey())
                ->whereIn('status', [SupportStatus::Open->value, SupportStatus::Assigned->value])
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            if ($existing === null) {
                $agent = $this->eligibleAgent();

                $conversation = SupportConversation::query()->create([
                    'user_id' => null,
                    'agent_id' => $agent?->getKey(),
                    'widget_conversation_id' => $widgetConversation->getKey(),
                    'status' => $agent !== null ? SupportStatus::Assigned : SupportStatus::Open,
                    'message_count' => 0,
                    'last_message_at' => now(),
                ]);

                $this->append($conversation, null, SupportMessageRole::System, $this->welcomeLine($agent));
                // The staff announcement above already told the agent about
                // this conversation, so the triggering message stays quiet.
                $this->append($conversation, null, SupportMessageRole::User, $message, notify: false);

                return ['conversation' => $conversation, 'assigned_now' => $agent !== null, 'created' => true];
            }

            $assignedNow = false;

            // A ticket that was waiting for staff picks up the first agent
            // who became free since it opened.
            if ($existing->status === SupportStatus::Open && $existing->agent_id === null) {
                $agent = $this->eligibleAgent();

                if ($agent !== null) {
                    $existing->forceFill([
                        'agent_id' => $agent->getKey(),
                        'status' => SupportStatus::Assigned,
                    ])->save();

                    $this->append($existing, null, SupportMessageRole::System, $this->welcomeLine($agent));
                    $assignedNow = true;
                }
            }

            $this->append($existing, null, SupportMessageRole::User, $message);

            return ['conversation' => $existing, 'assigned_now' => $assignedNow, 'created' => false];
        });

        $conversation = $result['conversation'];

        if ($result['assigned_now']) {
            $this->announceToAgent($conversation);
        } elseif ($result['created']) {
            $this->announceWaiting($conversation);
        }

        return $conversation;
    }

    /**
     * Append a message from either side and tell the other side about it.
     */
    public function reply(SupportConversation $conversation, User $sender, string $content): SupportMessage
    {
        $role = $sender->getKey() === $conversation->user_id
            ? SupportMessageRole::User
            : SupportMessageRole::Agent;

        return DB::transaction(
            fn (): SupportMessage => $this->append($conversation, $sender, $role, $content),
        );
    }

    /**
     * Close the ticket from the admin inbox. The visitor learns about it
     * through the widget's support polling.
     */
    public function resolve(SupportConversation $conversation, ?User $actor = null): void
    {
        DB::transaction(function () use ($conversation, $actor): void {
            if (! $conversation->status->isLive()) {
                return;
            }

            $conversation->forceFill([
                'status' => SupportStatus::Resolved,
                'resolved_at' => now(),
            ])->save();

            $by = $actor?->name ?? 'the support team';

            $this->append(
                $conversation,
                null,
                SupportMessageRole::System,
                'Conversation resolved by '.$by.'.',
                notify: false,
            );
        });
    }

    /**
     * The staff member with the fewest live tickets. Null when no admin or
     * support agent is available, which leaves the conversation open for the
     * next person to pick up.
     */
    public function eligibleAgent(): ?User
    {
        return User::query()
            ->whereIn('role', [UserRole::Admin, UserRole::Support])
            ->where('is_banned', false)
            ->withCount([
                'assignedSupportConversations as open_support_count' => fn ($query) => $query->whereIn('status', [
                    SupportStatus::Open->value,
                    SupportStatus::Assigned->value,
                ]),
            ])
            ->orderBy('open_support_count')
            ->orderBy('id')
            ->first();
    }

    private function append(
        SupportConversation $conversation,
        ?User $sender,
        SupportMessageRole $role,
        string $content,
        bool $notify = true,
    ): SupportMessage {
        $message = $conversation->messages()->create([
            'sender_id' => $sender?->getKey(),
            'role' => $role,
            'content' => $content,
            'read_at' => $role === SupportMessageRole::System ? now() : null,
        ]);

        $conversation->forceFill([
            'message_count' => (int) $conversation->message_count + 1,
            'last_message_at' => now(),
        ])->save();

        if (! $notify) {
            return $message;
        }

        match ($role) {
            SupportMessageRole::Agent => $this->markVisitorMessagesRead($conversation),
            SupportMessageRole::User => $this->notifyStaff($conversation, $message),
            SupportMessageRole::System => null,
        };

        return $message;
    }

    private function announceToAgent(SupportConversation $conversation): void
    {
        $agent = $conversation->agent;

        if ($agent === null) {
            return;
        }

        Notifier::make()
            ->type(NotificationType::ConversationEscalated)
            ->to($agent)
            ->title('A visitor requested human support')
            ->body('Conversation #'.$conversation->getKey().' is assigned to you and waiting for a reply.')
            ->link(route('admin.support.show', $conversation), 'Open support inbox')
            ->dedupe('support.conversation.started.'.$conversation->getKey())
            ->send();
    }

    /**
     * The brand-new ticket nobody could be assigned to: every available
     * staff member sees it once, and the dedupe key keeps a retry from
     * re-toasting the same request.
     */
    private function announceWaiting(SupportConversation $conversation): void
    {
        User::query()
            ->whereIn('role', [UserRole::Admin, UserRole::Support])
            ->where('is_banned', false)
            ->get()
            ->each(fn (User $staff) => Notifier::make()
                ->type(NotificationType::ConversationEscalated)
                ->to($staff)
                ->title('A visitor requested human support')
                ->body('Conversation #'.$conversation->getKey().' is waiting for an agent.')
                ->link(route('admin.support.show', $conversation), 'Open support inbox')
                ->dedupe('support.conversation.waiting.'.$conversation->getKey())
                ->send());
    }

    /**
     * A new visitor message: the assigned agent hears about it, or every
     * available staff member does while the ticket is still unassigned.
     */
    private function notifyStaff(SupportConversation $conversation, SupportMessage $message): void
    {
        $agent = $conversation->agent;

        $recipients = $agent !== null
            ? $agent
            : User::query()
                ->whereIn('role', [UserRole::Admin, UserRole::Support])
                ->where('is_banned', false)
                ->get();

        Notifier::make()
            ->type(NotificationType::ConversationReplied)
            ->to($recipients)
            ->title($agent !== null ? 'Support conversation needs a reply' : 'Unassigned support conversation')
            ->body('Visitor wrote in conversation #'.$conversation->getKey().': '.mb_substr($message->content, 0, 160))
            ->link(route('admin.support.show', $conversation), 'Open support inbox')
            ->group('support.'.$conversation->getKey())
            ->dedupe('support.message.'.$message->getKey())
            ->send();
    }

    /**
     * An agent reply supersedes anything the visitor had queued: the widget
     * shows replies live, so unread tracking only matters staff-side.
     */
    private function markVisitorMessagesRead(SupportConversation $conversation): void
    {
        $conversation->messages()
            ->whereNull('read_at')
            ->where('role', SupportMessageRole::User->value)
            ->update(['read_at' => now()]);
    }

    private function welcomeLine(?User $agent): string
    {
        return $agent !== null
            ? "You're connected with ".$agent->name.' from the DocuMind support team.'
            : 'Your request was received. A support agent will pick it up as soon as one is free.';
    }
}
