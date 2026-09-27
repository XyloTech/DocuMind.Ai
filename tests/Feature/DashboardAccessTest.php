<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get(route('dashboard'))->assertRedirect(route('login'));
        $this->get(route('admin.dashboard'))->assertRedirect(route('login'));
    }

    public function test_guests_see_the_landing_page_at_the_root(): void
    {
        $response = $this->get(route('home'));

        $response->assertOk();
        $response->assertViewIs('landing');
        $response->assertSee('data-landing', false);
        $response->assertSee('Create your support bot');
        $response->assertSee('how-it-works.mp4');
        $response->assertSee('Trusted by product', false);
        $response->assertSee('l-hero-spark', false);
    }

    public function test_authenticated_users_can_view_their_workspace(): void
    {
        $user = User::factory()->withCredits(7)->create();

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertOk();
        $response->assertViewHas('user', $user);
        $response->assertSee('credits remaining');
        $response->assertSee($user->name);
    }

    public function test_the_home_page_redirects_authenticated_users_to_the_dashboard(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('home'))
            ->assertRedirect(route('dashboard'));
    }

    /**
     * A malformed Blade comment in partials.notifications once swallowed the
     * wrapper's opening <div>, so the orphan </div> closed the whole page
     * shell and every authenticated screen rendered as a black shell with the
     * content pushed a full viewport down. The base URL sits on that wrapper,
     * so it is the marker that the header markup survived compilation.
     */
    public function test_authenticated_pages_keep_the_notifications_wrapper(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('data-notifications-base', false);

        $page = $this->actingAs($user)
            ->get(route('widget.index'))
            ->assertOk();

        $page->assertSee('data-notifications-base', false);

        // Exactly one div — the page shell — must still be open before
        // <main>. A stray </div> (what the broken comment produced) closes
        // that shell early and pushes <main> a full viewport down, leaving
        // an empty black page behind the nav.
        $header = substr($page->getContent(), 0, (int) strpos($page->getContent(), '<main'));

        $this->assertSame(
            1,
            substr_count($header, '<div') - substr_count($header, '</div>'),
            'Only the page shell may remain open before <main>.',
        );
    }
}
