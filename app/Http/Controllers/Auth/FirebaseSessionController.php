<?php

namespace App\Http\Controllers\Auth;

use App\Enums\NotificationType;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\FirebaseIdentityLookup;
use App\Services\Notifier;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class FirebaseSessionController extends Controller
{
    public function store(Request $request, FirebaseIdentityLookup $identities): JsonResponse
    {
        $validated = $request->validate([
            'id_token' => ['required', 'string', 'max:8192'],
        ]);

        try {
            $identity = $identities->lookup($validated['id_token']);
        } catch (ConnectionException|RuntimeException) {
            return response()->json([
                'message' => 'Google sign-in is temporarily unavailable. Please try again.',
            ], 503);
        }

        if ($identity === null) {
            return response()->json([
                'message' => 'Google could not verify this account. Choose a verified Google account and try again.',
            ], 422);
        }

        $user = User::query()->where('firebase_uid', $identity['uid'])->first();
        $emailUser = User::query()->where('email', $identity['email'])->first();

        if ($user !== null && $emailUser !== null && ! $user->is($emailUser)) {
            return response()->json(['message' => 'This Google account is already linked to another profile.'], 409);
        }

        $user ??= $emailUser;

        if ($user?->firebase_uid !== null && $user->firebase_uid !== $identity['uid']) {
            return response()->json(['message' => 'This email is linked to a different Google account.'], 409);
        }

        if ($user?->isBanned()) {
            return response()->json(['message' => 'This account is suspended. Contact your administrator.'], 403);
        }

        if ($user === null && ! config('billing.registration_enabled')) {
            return response()->json(['message' => 'New accounts are currently disabled. Contact your administrator.'], 403);
        }

        $isNewUser = $user === null;

        $user = DB::transaction(function () use ($identity, $user, $isNewUser): User {
            $user ??= User::query()->create([
                'name' => $identity['name'],
                'email' => $identity['email'],
                'password' => Str::random(80),
                'firebase_uid' => $identity['uid'],
                'avatar_url' => $identity['avatar_url'],
            ]);

            $user->forceFill([
                'name' => $identity['name'],
                'email' => $identity['email'],
                'firebase_uid' => $identity['uid'],
                'avatar_url' => $identity['avatar_url'],
                'email_verified_at' => $user->email_verified_at ?? now(),
            ])->save();

            if ($isNewUser) {
                $user->grantCredits((int) config('billing.welcome_credits'));
            }

            return $user;
        });

        $user->forceFill(['last_login_at' => now()])->save();
        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->put('firebase_authenticated_at', now()->getTimestamp());

        if (! $isNewUser) {
            Notifier::make()
                ->type(NotificationType::NewSignIn)
                ->to($user)
                ->title('New sign-in to your account')
                ->body('Your account was signed into with Google at '.now()->format('M j, Y g:i A').'. If this was not you, reset your password.')
                ->dedupe('security.signin.'.$user->getKey().'.'.now()->toDateString())
                ->send();
        }

        return response()->json(['redirect' => route('dashboard')]);
    }
}
