<?php

namespace App\Policies;

use App\Models\Chat;
use App\Models\User;

class ChatPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Chat $chat): bool
    {
        return $this->owns($user, $chat);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Chat $chat): bool
    {
        return $this->owns($user, $chat);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Chat $chat): bool
    {
        return $this->owns($user, $chat);
    }

    private function owns(User $user, Chat $chat): bool
    {
        if ($user->id === $chat->user_id) {
            return true;
        }

        return $chat->workspace_id !== null
            && $user->workspaces()->whereKey($chat->workspace_id)->exists();
    }
}
