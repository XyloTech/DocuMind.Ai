<?php

namespace App\Policies;

use App\Models\SupportConversation;
use App\Models\User;

class SupportConversationPolicy
{
    /**
     * The customer who opened it, plus any staff member who answers it.
     */
    public function view(User $user, SupportConversation $conversation): bool
    {
        return $this->party($user, $conversation);
    }

    /**
     * Both sides of the conversation can add messages to it.
     */
    public function update(User $user, SupportConversation $conversation): bool
    {
        return $this->party($user, $conversation);
    }

    /**
     * Only the customer who opened it can close it from their side; staff
     * close conversations through the admin inbox.
     */
    public function close(User $user, SupportConversation $conversation): bool
    {
        return $conversation->user_id === $user->getKey();
    }

    private function party(User $user, SupportConversation $conversation): bool
    {
        if ($conversation->user_id === $user->getKey()) {
            return true;
        }

        if ($conversation->agent_id === $user->getKey()) {
            return true;
        }

        return $user->hasAdminAccess();
    }
}
