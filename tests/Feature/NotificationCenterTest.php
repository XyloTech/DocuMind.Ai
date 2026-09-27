<?php

namespace Tests\Feature;

use App\Enums\NotificationType;
use App\Models\Site;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Notifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationCenterTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function raise(User $user, NotificationType $type, array $overrides = []): void
    {
        Notifier::make()
            ->type($type)
            ->to($user)
            ->title($overrides['title'] ?? 'Something happened')
            ->body($overrides['body'] ?? 'Details of the event.')
            ->link($overrides['link'] ?? null)
            ->dedupe($overrides['dedupe'] ?? null)
            ->send();
    }

    public function test_the_notification_centre_renders_for_a_signed_in_user(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('notifications.index'));

        $response->assertOk();
        $response->assertSee('data-mark-all', false);
        $response->assertSee('Notifications', false);
    }

    public function test_the_feed_returns_only_the_current_users_notifications(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $this->raise($user, NotificationType::ConversationCreated, ['title' => 'Mine']);
        $this->raise($other, NotificationType::ConversationCreated, ['title' => 'Theirs']);

        $response = $this->actingAs($user)->getJson(route('notifications.feed'));

        $response->assertOk();
        $items = $response->json('notifications');

        $this->assertCount(1, $items);
        $this->assertSame('Mine', $items[0]['title']);
        $this->assertSame(1, $response->json('unread'));
    }

    public function test_marking_a_notification_read_scopes_to_the_owner(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $this->raise($user, NotificationType::KnowledgeProcessed, ['title' => 'PDF ready']);
        $row = $user->notificationsInApp()->firstOrFail();

        $this->actingAs($other)
            ->postJson(route('notifications.read', $row))
            ->assertNotFound();

        $response = $this->actingAs($user)->postJson(route('notifications.read', $row));

        $response->assertOk();
        $this->assertSame(0, $response->json('unread'));
        $this->assertNotNull($row->refresh()->read_at);
    }

    public function test_read_all_marks_every_unread_notification(): void
    {
        $user = User::factory()->create();

        $this->raise($user, NotificationType::ConversationCreated);
        $this->raise($user, NotificationType::WidgetUpdated);

        $response = $this->actingAs($user)->postJson(route('notifications.read-all'));

        $response->assertOk();
        $this->assertSame(0, $response->json('unread'));
        $this->assertSame(0, $user->notificationsInApp()->unread()->count());
    }

    public function test_poll_only_returns_rows_created_after_the_cursor(): void
    {
        $user = User::factory()->create();

        $this->raise($user, NotificationType::ConversationCreated, ['title' => 'Old']);
        $cursor = now()->addSecond()->toIso8601String();

        $this->travel(2)->seconds();

        $this->raise($user, NotificationType::WidgetUpdated, ['title' => 'Fresh']);

        $response = $this->actingAs($user)->getJson(route('notifications.poll', ['since' => $cursor]));

        $response->assertOk();

        $items = $response->json('notifications');
        $this->assertCount(1, $items);
        $this->assertSame('Fresh', $items[0]['title']);
        $this->assertNotNull($response->json('server_time'));

        $everything = $this->actingAs($user)->getJson(route('notifications.poll'));
        $everything->assertOk();
        $this->assertCount(2, $everything->json('notifications'));
    }

    public function test_preferences_persist_and_flash_a_status_line(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->patch(route('settings.notifications.update'), [
            'non_essential' => '0',
            'browser' => '1',
            'email' => '0',
            'categories' => [
                'conversation' => ['in_app' => '0', 'email' => '0'],
                'billing' => ['in_app' => '1', 'email' => '1'],
            ],
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('status', 'Notification preferences saved.');

        $prefs = $user->refresh()->notificationPreferences();

        $this->assertFalse($prefs['non_essential']);
        $this->assertTrue($prefs['browser']);
        $this->assertFalse($prefs['email']);
        $this->assertFalse($prefs['categories']['conversation']['in_app']);
        $this->assertTrue($prefs['categories']['billing']['in_app']);
        $this->assertTrue($prefs['categories']['billing']['email']);
        // Categories the form did not mention fall back to sensible defaults.
        $this->assertTrue($prefs['categories']['knowledge']['in_app']);
    }

    public function test_invalid_preference_values_are_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->patch(route('settings.notifications.update'), ['browser' => 'maybe'])
            ->assertSessionHasErrors('browser');
    }

    public function test_essential_notifications_ignore_the_non_essential_opt_out(): void
    {
        $user = User::factory()->create();
        $user->forceFill([
            'notification_preferences' => ['non_essential' => false, 'categories' => []],
        ])->save();

        $this->raise($user, NotificationType::ConversationCreated);
        $this->raise($user, NotificationType::WidgetUpdated);

        $this->assertSame(0, $user->notificationsInApp()->count());

        $this->raise($user, NotificationType::AdminAction);

        $this->assertSame(1, $user->notificationsInApp()->count());
    }

    public function test_a_disabled_category_filters_out_its_notifications(): void
    {
        $user = User::factory()->create();
        $user->forceFill([
            'notification_preferences' => [
                'categories' => ['widget' => ['in_app' => false, 'email' => false]],
            ],
        ])->save();

        $this->raise($user, NotificationType::WidgetUpdated);
        $this->raise($user, NotificationType::KnowledgeProcessed);

        $rows = $user->notificationsInApp()->get();

        $this->assertCount(1, $rows);
        $this->assertSame(NotificationType::KnowledgeProcessed->value, $rows->first()->type->value);
    }

    public function test_a_dedupe_key_updates_the_existing_row_without_duplicating_it(): void
    {
        $user = User::factory()->create();

        $this->raise($user, NotificationType::WidgetQuotaReached, [
            'title' => 'Quota reached',
            'dedupe' => 'site.1.quota.2026-09',
        ]);

        $row = $user->notificationsInApp()->whereNotNull('dedupe_key')->firstOrFail();
        $originalCreatedAt = $row->created_at;

        $this->travel(5)->minutes();

        $this->raise($user, NotificationType::WidgetQuotaReached, [
            'title' => 'Quota reached (final)',
            'dedupe' => 'site.1.quota.2026-09',
        ]);

        $rows = $user->notificationsInApp()->get();

        $this->assertCount(1, $rows);
        $row->refresh();
        $this->assertSame('Quota reached (final)', $row->title);
        // created_at must not move, otherwise the poller would re-toast it.
        $this->assertTrue($originalCreatedAt->equalTo($row->created_at));
    }

    public function test_rotating_a_site_key_notifies_workspace_members_except_the_actor(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();

        $workspace = Workspace::factory()->for($owner, 'owner')->create();
        $workspace->members()->attach($owner->getKey(), ['role' => 'owner']);
        $workspace->members()->attach($member->getKey(), ['role' => 'member']);

        $site = Site::factory()->create([
            'user_id' => $owner->getKey(),
            'workspace_id' => $workspace->getKey(),
        ]);

        $this->actingAs($owner)
            ->post(route('widget.rotate-key', $site))
            ->assertRedirect();

        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $member->getKey(),
            'type' => NotificationType::WidgetKeyRotated->value,
        ]);

        $this->assertDatabaseMissing('user_notifications', [
            'user_id' => $owner->getKey(),
            'type' => NotificationType::WidgetKeyRotated->value,
        ]);
    }
}
