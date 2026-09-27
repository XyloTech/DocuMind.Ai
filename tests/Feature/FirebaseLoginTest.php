<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FirebaseLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_verified_firebase_identity_creates_a_user_and_a_laravel_session(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'identitytoolkit.googleapis.com/v1/accounts:lookup*' => Http::response([
                'users' => [[
                    'localId' => 'firebase-user-1',
                    'email' => 'ada@example.com',
                    'emailVerified' => true,
                    'displayName' => 'Ada Lovelace',
                    'photoUrl' => 'https://lh3.googleusercontent.com/avatar.png',
                ]],
            ]),
        ]);

        $response = $this->postJson(route('login.firebase'), ['id_token' => 'firebase-id-token']);

        $user = User::query()->where('firebase_uid', 'firebase-user-1')->firstOrFail();

        $response->assertOk()->assertJsonPath('redirect', route('dashboard'));
        $this->assertAuthenticatedAs($user);
        $this->assertSame('Ada Lovelace', $user->name);
        $this->assertSame('ada@example.com', $user->email);
        $this->assertNotNull($user->email_verified_at);
        $this->assertSame('https://lh3.googleusercontent.com/avatar.png', $user->avatar_url);
        $this->assertSame((int) config('billing.welcome_credits'), $user->credits);
        $this->assertNotNull($user->last_login_at);
        $response->assertSessionHas('firebase_authenticated_at');

        $this->get(route('dashboard'))->assertOk();

        $workspace = $user->ownedWorkspaces()->firstOrFail();
        $this->assertSame($workspace->getKey(), session('active_workspace_id'));
        $this->assertDatabaseHas('user_workspace', [
            'user_id' => $user->getKey(),
            'workspace_id' => $workspace->getKey(),
            'role' => 'owner',
        ]);

        Http::assertSent(fn (Request $request): bool =>
            str_starts_with($request->url(), 'https://identitytoolkit.googleapis.com/v1/accounts:lookup?key=')
            && $request['idToken'] === 'firebase-id-token'
        );
    }

    public function test_verified_google_email_links_to_an_existing_account_without_resetting_its_role_or_credits(): void
    {
        $existing = User::factory()->create([
            'email' => 'ada@example.com',
            'credits' => 73,
        ]);

        $this->fakeIdentity('firebase-user-2');

        $response = $this->postJson(route('login.firebase'), ['id_token' => 'firebase-id-token']);

        $response->assertOk();
        $this->assertAuthenticatedAs($existing);
        $this->assertSame('firebase-user-2', $existing->fresh()->firebase_uid);
        $this->assertSame(73, $existing->fresh()->credits);
    }

    public function test_invalid_and_unverified_firebase_tokens_do_not_create_accounts(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'identitytoolkit.googleapis.com/v1/accounts:lookup*' => Http::response([
                'error' => ['message' => 'INVALID_ID_TOKEN'],
            ], 400),
        ]);

        $this->postJson(route('login.firebase'), ['id_token' => 'invalid-token'])
            ->assertUnprocessable();

        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_firebase_identity_must_have_a_verified_email(): void
    {
        $this->fakeIdentity('firebase-unverified', ['emailVerified' => false]);

        $this->postJson(route('login.firebase'), ['id_token' => 'firebase-id-token'])
            ->assertUnprocessable();

        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_banned_firebase_users_are_not_authenticated(): void
    {
        $banned = User::factory()->banned()->create(['email' => 'ada@example.com']);
        $this->fakeIdentity('firebase-banned');

        $this->postJson(route('login.firebase'), ['id_token' => 'firebase-id-token'])
            ->assertForbidden();

        $this->assertGuest();
        $this->assertNull($banned->fresh()->firebase_uid);
    }

    public function test_firebase_outage_returns_a_retryable_error_without_creating_an_account(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'identitytoolkit.googleapis.com/v1/accounts:lookup*' => Http::response([], 503),
        ]);

        $this->postJson(route('login.firebase'), ['id_token' => 'firebase-id-token'])
            ->assertStatus(503)
            ->assertJsonPath('message', 'Google sign-in is temporarily unavailable. Please try again.');

        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_new_accounts_are_rejected_when_registration_is_disabled(): void
    {
        config(['billing.registration_enabled' => false]);
        $this->fakeIdentity('firebase-user-disabled-registration');

        $this->postJson(route('login.firebase'), ['id_token' => 'firebase-id-token'])
            ->assertForbidden();

        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
    }

    /** @param array<string, mixed> $overrides */
    private function fakeIdentity(string $uid, array $overrides = []): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'identitytoolkit.googleapis.com/v1/accounts:lookup*' => Http::response([
                'users' => [[
                    'localId' => $uid,
                    'email' => 'ada@example.com',
                    'emailVerified' => true,
                    'displayName' => 'Ada Lovelace',
                    'photoUrl' => null,
                    ...$overrides,
                ]],
            ]),
        ]);
    }
}
