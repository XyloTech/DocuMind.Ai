<?php

namespace App\Policies;

use App\Models\Document;
use App\Models\User;

class DocumentPolicy
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
     *
     * Staff review customer knowledge sources from the admin panel, so
     * read access extends to every staff role while writes stay limited to
     * administrators.
     */
    public function view(User $user, Document $document): bool
    {
        return $this->owns($user, $document) || $user->hasAdminAccess();
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
    public function update(User $user, Document $document): bool
    {
        return $this->owns($user, $document) || $user->canManagePlatform();
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Document $document): bool
    {
        return $this->owns($user, $document) || $user->canManagePlatform();
    }

    private function owns(User $user, Document $document): bool
    {
        if ($user->id === $document->user_id) {
            return true;
        }

        return $document->workspace_id !== null
            && $user->workspaces()->whereKey($document->workspace_id)->exists();
    }
}
