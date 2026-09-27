<?php

namespace App\Http\Middleware;

use App\Models\Workspace;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class EnsureWorkspaceContext
{
    /**
     * Ensure every authenticated request is scoped to a valid workspace.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return $next($request);
        }

        $routeWorkspaceId = $request->route('workspace');
        $workspaceId = $routeWorkspaceId ?? $request->session()->get('active_workspace_id');

        if ($workspaceId !== null) {
            $workspace = Workspace::query()->find($workspaceId);

            if ($workspace === null || ! $workspace->isMember($user) && $workspace->owner_id !== $user->id) {
                if ($routeWorkspaceId !== null) {
                    abort(403, 'You do not belong to this workspace.');
                }

                $request->session()->forget('active_workspace_id');
            } else {
                $request->session()->put('active_workspace_id', $workspace->getKey());

                return $next($request);
            }
        }

        $workspace = DB::transaction(function () use ($user): Workspace {
            $lockedUser = $user::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
            $workspace = $lockedUser->workspaces()->first() ?? $lockedUser->ownedWorkspaces()->first();

            if ($workspace === null) {
                $name = trim($lockedUser->name);
                $workspace = $lockedUser->ownedWorkspaces()->create([
                    'name' => $name === '' ? 'My workspace' : $name.' workspace',
                    'slug' => Str::slug($name ?: 'workspace').'-'.$lockedUser->getKey(),
                ]);
            }

            if (! $workspace->members()->whereKey($lockedUser->getKey())->exists()) {
                $workspace->members()->attach($lockedUser->getKey(), [
                    'role' => $workspace->owner_id === $lockedUser->getKey() ? 'owner' : 'member',
                ]);
            }

            foreach (['documents', 'chats', 'sites'] as $table) {
                DB::table($table)
                    ->where('user_id', $lockedUser->getKey())
                    ->whereNull('workspace_id')
                    ->update(['workspace_id' => $workspace->getKey()]);
            }

            return $workspace;
        });

        $request->session()->put('active_workspace_id', $workspace->getKey());

        return $next($request);
    }
}
