<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    /**
     * Show the authenticated user's workspace.
     */
    public function __invoke(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();

        $workspaceId = $request->session()->get('active_workspace_id');

        return view('dashboard', [
            'user' => $user,
            'documents' => Document::query()
                ->when($workspaceId !== null, fn ($query) => $query->where('workspace_id', $workspaceId))
                ->when($workspaceId === null, fn ($query) => $query->where('user_id', $user->getKey()))
                ->orderByDesc('id')
                ->get(),
        ]);
    }
}
