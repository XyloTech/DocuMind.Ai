<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_screen_can_be_rendered(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('Continue with Google')
            ->assertDontSee('name="password"', false)
            ->assertDontSee('name="email"', false)
            ->assertDontSee('Forgot password');
    }

    public function test_password_login_and_registration_routes_are_unavailable(): void
    {
        $this->post('/login', ['email' => 'user@example.test', 'password' => 'secret'])
            ->assertStatus(405);
        $this->get('/register')->assertNotFound();
        $this->post('/register', [])->assertNotFound();
    }

    public function test_users_can_logout(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('logout'));

        $response->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_login_attempts_are_rate_limited(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'identitytoolkit.googleapis.com/v1/accounts:lookup*' => Http::response([
                'error' => ['message' => 'INVALID_ID_TOKEN'],
            ], 400),
        ]);

        foreach (range(1, 10) as $attempt) {
            $this->postJson(route('login.firebase'), ['id_token' => 'invalid-token'])
                ->assertUnprocessable();
        }

        $response = $this->postJson(route('login.firebase'), ['id_token' => 'invalid-token']);

        $response->assertStatus(429);
        $this->assertGuest();
    }
}
