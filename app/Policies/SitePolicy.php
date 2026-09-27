<?php

namespace App\Policies;

use App\Models\Site;
use App\Models\User;

class SitePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Site $site): bool
    {
        return $this->owns($user, $site);
    }

    public function viewLeads(User $user, Site $site): bool
    {
        if ($user->id === $site->user_id) {
            return true;
        }

        $workspace = $site->workspace;

        if ($workspace === null) {
            return false;
        }

        if ($workspace->owner_id === $user->getKey()) {
            return true;
        }

        return $workspace->members()
            ->whereKey($user->getKey())
            ->wherePivotIn('role', ['owner', 'admin', 'support'])
            ->exists();
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Site $site): bool
    {
        return $this->owns($user, $site);
    }

    public function delete(User $user, Site $site): bool
    {
        return $this->owns($user, $site);
    }

    private function owns(User $user, Site $site): bool
    {
        if ($user->id === $site->user_id) {
            return true;
        }

        return $site->workspace_id !== null
            && $user->workspaces()->whereKey($site->workspace_id)->exists();
    }
}
