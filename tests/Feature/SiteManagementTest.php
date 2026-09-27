<?php

namespace Tests\Feature;

use App\Enums\MessageStatus;
use App\Models\Document;
use App\Models\Site;
use App\Models\User;
use App\Models\WidgetEvent;
use App\Models\WidgetMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SiteManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_can_create_a_site_and_receives_a_public_key(): void
    {
        $user = User::factory()->create();
        $document = Document::factory()->create(['user_id' => $user->getKey()]);

        $response = $this->actingAs($user)->post(route('widget.store'), [
            'name' => 'Acme help centre',
            'monthly_quota' => 250,
        ]);

        $response->assertRedirect();

        $site = Site::query()->where('user_id', $user->getKey())->firstOrFail();

        $this->assertSame('Acme help centre', $site->name);
        $this->assertStringStartsWith('pk_', $site->site_key);
        $this->assertSame(250, $site->monthly_quota);
        $this->assertSame('bottom-left', $site->position->value);
        $this->assertNotNull($document);
    }

    public function test_the_install_snippet_contains_the_site_key(): void
    {
        $user = User::factory()->create();
        $document = Document::factory()->create(['user_id' => $user->getKey()]);

        $site = Site::factory()->create(['user_id' => $user->getKey()]);
        $site->documents()->attach($document);

        $page = $this->actingAs($user)->get(route('widget.show', $site));

        $page->assertOk();
        $page->assertSee('Install Embed Code', false);
        $page->assertSee('data-install', false);
        $page->assertSee('/widget.js', false);
        $page->assertSee($site->site_key, false);
        $page->assertSee('data-snippet-scroll="previous"', false);
        $page->assertSee('data-snippet-scroll="next"', false);
    }

    public function test_a_snippet_is_offered_for_every_supported_stack(): void
    {
        $user = User::factory()->create();
        $document = Document::factory()->create(['user_id' => $user->getKey()]);

        $site = Site::factory()->create(['user_id' => $user->getKey()]);
        $site->documents()->attach($document);

        $page = $this->actingAs($user)->get(route('widget.show', $site));

        $page->assertOk();

        $stacks = ['html', 'spa', 'react', 'next', 'vue', 'angular', 'svelte', 'wordpress'];

        foreach ($stacks as $stack) {
            $page->assertSee('data-snippet-panel="'.$stack.'"', false);
        }

        $page->assertDontSee('data-snippet-panel="webflow"', false);
        $page->assertDontSee('data-snippet-panel="manual"', false);

        $content = $page->getContent();

        // Every snippet must actually reference the bundle and this site's key,
        // otherwise the customer pastes something that loads nothing.
        foreach ($stacks as $stack) {
            preg_match(
                '/data-snippet-panel="'.preg_quote($stack, '/').'".*?<\/div>\s*<\/div>/s',
                $content,
                $panel,
            );

            $this->assertNotEmpty($panel, "no markup found for the {$stack} snippet");
            $this->assertStringContainsString('/widget.js', $panel[0], "{$stack} snippet has no bundle URL");
            $this->assertStringContainsString($site->site_key, $panel[0], "{$stack} snippet has no site key");
        }

        $this->assertStringContainsString('onUnmounted', $content);
        $this->assertStringContainsString('onMount } from', $content);
        $this->assertStringNotContainsString('&lt;script context=&quot;module&quot;&gt;', $content);
    }

    public function test_the_snippet_origin_follows_the_host_the_owner_is_using(): void
    {
        // `app.url` is whatever .env says, which in most local and staging
        // environments is a placeholder the owner's visitors cannot reach.
        config(['app.url' => 'https://placeholder.invalid']);

        $user = User::factory()->create();
        $site = Site::factory()->create(['user_id' => $user->getKey()]);

        $page = $this->actingAs($user)->get(route('widget.show', $site));

        $page->assertOk();
        $page->assertDontSee('placeholder.invalid', false);
        $page->assertSee('http://localhost:8000/widget.js', false);
    }

    public function test_the_preview_page_loads_the_real_bundle_for_the_owner(): void
    {
        $user = User::factory()->create();
        $document = Document::factory()->create(['user_id' => $user->getKey()]);

        $site = Site::factory()->create(['user_id' => $user->getKey()]);
        $site->documents()->attach($document);

        $page = $this->actingAs($user)->get(route('widget.preview', $site));

        $page->assertOk();
        $page->assertSee('/widget.js', false);
        $page->assertSee('data-site-key="'.$site->site_key.'"', false);
        $page->assertDontSee('Widget is not live', false);
    }

    public function test_the_preview_warns_when_the_widget_is_paused(): void
    {
        $user = User::factory()->create();
        $site = Site::factory()->create(['user_id' => $user->getKey(), 'enabled' => false]);

        $page = $this->actingAs($user)->get(route('widget.preview', $site));

        $page->assertOk();
        $page->assertSee('Widget is not live', false);
    }

    public function test_another_user_cannot_preview_someone_elses_widget(): void
    {
        $site = Site::factory()->create();
        $intruder = User::factory()->create();

        $this->actingAs($intruder)->get(route('widget.preview', $site))->assertForbidden();
    }

    public function test_branding_and_documents_can_be_updated(): void
    {
        $user = User::factory()->create();
        $first = Document::factory()->create(['user_id' => $user->getKey()]);
        $second = Document::factory()->create(['user_id' => $user->getKey()]);

        $site = Site::factory()->create(['user_id' => $user->getKey()]);
        $site->documents()->attach($first);

        $this->actingAs($user)->put(route('widget.update', $site), [
            'name' => 'Renamed',
            'bot_name' => 'Acme Helper',
            'greeting' => 'Hello! How can we help?',
            'accent_color' => '#0EA5E9',
            'theme' => 'light',
            'launcher_icon' => 'spark',
            'position' => 'bottom-right',
            'monthly_quota' => 500,
            'enabled' => '1',
            'collect_email' => '1',
            'documents' => [$second->getKey()],
        ])->assertRedirect();

        $site->refresh();

        $this->assertSame('Acme Helper', $site->bot_name);
        $this->assertSame('#0ea5e9', $site->accent_color);
        $this->assertSame('light', $site->theme);
        $this->assertSame('spark', $site->launcher_icon);
        $this->assertSame('bottom-right', $site->position->value);
        $this->assertTrue($site->collect_email);
        $this->assertSame([$second->getKey()], $site->documents()->pluck('documents.id')->all());
    }

    public function test_blobatar_avatar_settings_persist_and_reach_the_widget_config(): void
    {
        $user = User::factory()->create();
        $site = Site::factory()->create(['user_id' => $user->getKey()]);

        $this->actingAs($user)->put(route('widget.update', $site), [
            'name' => 'Site',
            'bot_name' => 'Acme Helper',
            'accent_color' => '#4f46e5',
            'position' => 'bottom-left',
            'monthly_quota' => 100,
            'enabled' => '1',
            'blobatar_seed' => 'night-owl',
            'blobatar_size' => 48,
            'blobatar_background' => 'circle',
            'blobatar_hue' => 210,
            'blobatar_tone' => 0.42,
            'blobatar_expression' => 'wink',
            'blobatar_animation' => 'hover',
        ])->assertRedirect();

        $site->refresh();

        $this->assertSame('night-owl', $site->blobatar_seed);
        $this->assertSame(48, $site->blobatar_size);
        $this->assertSame('circle', $site->blobatar_background);
        $this->assertSame(210, $site->blobatar_hue);
        $this->assertEqualsWithDelta(0.42, $site->blobatar_tone, 0.001);
        $this->assertSame('wink', $site->blobatar_expression);
        $this->assertSame('hover', $site->blobatar_animation);

        $this->assertEquals([
            'seed' => 'night-owl',
            'size' => 48,
            'hue' => 210,
            'tone' => 0.42,
            'background' => 'circle',
            'expression' => 'wink',
            'animation' => 'hover',
        ], $site->widgetConfig()['blobatar']);
    }

    public function test_auto_avatar_fields_clear_pinned_values_instead_of_sticking(): void
    {
        $user = User::factory()->create();
        $site = Site::factory()->create([
            'user_id' => $user->getKey(),
            'blobatar_seed' => 'pinned-seed',
            'blobatar_hue' => 120,
            'blobatar_tone' => 0.9,
        ]);

        // The Auto checkboxes submit nothing for the pinned fields: an absent
        // key must clear the previous pin, not leave it stuck forever.
        $this->actingAs($user)->put(route('widget.update', $site), [
            'name' => 'Site',
            'bot_name' => 'Acme Helper',
            'accent_color' => '#4f46e5',
            'position' => 'bottom-left',
            'monthly_quota' => 100,
            'enabled' => '1',
        ])->assertRedirect();

        $site->refresh();

        $this->assertNull($site->blobatar_seed);
        $this->assertNull($site->blobatar_hue);
        $this->assertNull($site->blobatar_tone);
        $this->assertSame('Acme Helper', $site->widgetConfig()['blobatar']['seed']);
        $this->assertNull($site->widgetConfig()['blobatar']['hue']);
        $this->assertNull($site->widgetConfig()['blobatar']['tone']);
    }

    public function test_avatar_settings_reject_values_outside_their_ranges(): void
    {
        $user = User::factory()->create();
        $site = Site::factory()->create(['user_id' => $user->getKey()]);

        $this->actingAs($user)->put(route('widget.update', $site), [
            'name' => 'Site',
            'bot_name' => 'Bot',
            'accent_color' => '#4f46e5',
            'position' => 'bottom-left',
            'monthly_quota' => 100,
            'enabled' => '1',
            'blobatar_size' => 100,
            'blobatar_hue' => 400,
            'blobatar_tone' => 1.5,
            'blobatar_expression' => 'manic',
            'blobatar_animation' => 'shake',
            'blobatar_background' => 'triangle',
        ])->assertSessionHasErrors([
            'blobatar_size',
            'blobatar_hue',
            'blobatar_tone',
            'blobatar_expression',
            'blobatar_animation',
            'blobatar_background',
        ]);
    }

    public function test_documents_owned_by_someone_else_cannot_be_linked(): void
    {
        $user = User::factory()->create();
        $foreign = Document::factory()->create();

        $site = Site::factory()->create(['user_id' => $user->getKey()]);

        $this->actingAs($user)->put(route('widget.update', $site), [
            'name' => 'Site',
            'bot_name' => 'Bot',
            'accent_color' => '#4f46e5',
            'position' => 'bottom-left',
            'monthly_quota' => 100,
            'enabled' => '1',
            'documents' => [$foreign->getKey()],
        ])->assertRedirect();

        $this->assertCount(0, $site->fresh()->documents);
    }

    public function test_a_user_cannot_touch_another_users_site(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $site = Site::factory()->create(['user_id' => $owner->getKey()]);

        $this->actingAs($intruder)->get(route('widget.show', $site))->assertForbidden();
        $this->actingAs($intruder)->delete(route('widget.destroy', $site))->assertForbidden();
        $this->actingAs($intruder)->post(route('widget.toggle', $site))->assertForbidden();
        $this->actingAs($intruder)->post(route('widget.rotate-key', $site))->assertForbidden();
        $this->actingAs($intruder)->get(route('widget.analytics', $site))->assertForbidden();

        $this->assertDatabaseHas('sites', ['id' => $site->getKey(), 'enabled' => true]);
    }

    public function test_guests_cannot_reach_the_widget_screens(): void
    {
        $this->get(route('widget.index'))->assertRedirect(route('login'));
    }

    public function test_regenerating_the_key_invalidates_the_old_one(): void
    {
        $user = User::factory()->create();
        $site = Site::factory()->create(['user_id' => $user->getKey()]);
        $old = $site->site_key;

        $this->actingAs($user)->post(route('widget.rotate-key', $site))->assertRedirect();

        $this->assertNotSame($old, $site->fresh()->site_key);
    }

    public function test_the_widget_can_be_paused_and_resumed(): void
    {
        $user = User::factory()->create();
        $site = Site::factory()->create(['user_id' => $user->getKey()]);

        $this->actingAs($user)->postJson(route('widget.toggle', $site))->assertOk()->assertJson(['enabled' => false]);
        $this->actingAs($user)->postJson(route('widget.toggle', $site))->assertOk()->assertJson(['enabled' => true]);
    }

    public function test_deleting_a_site_keeps_its_documents(): void
    {
        $user = User::factory()->create();
        $document = Document::factory()->create(['user_id' => $user->getKey()]);
        $site = Site::factory()->create(['user_id' => $user->getKey()]);
        $site->documents()->attach($document);

        $this->actingAs($user)->delete(route('widget.destroy', $site))->assertRedirect(route('widget.index'));

        $this->assertDatabaseMissing('sites', ['id' => $site->getKey()]);
        $this->assertDatabaseHas('documents', ['id' => $document->getKey()]);
    }

    public function test_analytics_summarise_visitor_activity(): void
    {
        $user = User::factory()->create();
        $site = Site::factory()->create(['user_id' => $user->getKey()]);

        WidgetMessage::factory()->create([
            'site_id' => $site->getKey(),
            'content' => 'How do I reset my password?',
        ]);
        WidgetMessage::factory()->assistant('Reset it from the settings page.')->create([
            'site_id' => $site->getKey(),
            'was_helpful' => true,
        ]);
        WidgetMessage::factory()->assistant('I cannot find this information in the document.')->create([
            'site_id' => $site->getKey(),
            'was_refused' => true,
            'status' => MessageStatus::Complete,
        ]);

        $response = $this->actingAs($user)->getJson(route('widget.analytics', $site));

        $response->assertOk()
            ->assertJsonPath('totals.refused', 1)
            ->assertJsonPath('totals.helpful', 1)
            ->assertJsonPath('totals.questions', 1)
            ->assertJsonStructure(['totals', 'outcomes', 'failures', 'daily', 'knowledge', 'documents', 'events', 'recent', 'transcripts']);
    }

    public function test_analytics_counts_widget_engagement_events(): void
    {
        $user = User::factory()->create();
        $site = Site::factory()->create(['user_id' => $user->getKey()]);

        WidgetEvent::factory()->count(3)->create(['site_id' => $site->getKey(), 'type' => 'widget_loaded']);
        WidgetEvent::factory()->create(['site_id' => $site->getKey(), 'type' => 'launcher_open']);
        WidgetEvent::factory()->identified()->create(['site_id' => $site->getKey(), 'type' => 'message_sent']);
        WidgetEvent::factory()->identified()->create(['site_id' => $site->getKey(), 'type' => 'widget_loaded']);

        // Another site's engagement must not leak into this one's counts.
        WidgetEvent::factory()->create(['type' => 'widget_loaded']);

        $response = $this->actingAs($user)->getJson(route('widget.analytics', $site));

        $response->assertOk()
            ->assertJsonPath('events.loads', 4)
            ->assertJsonPath('events.opens', 1)
            ->assertJsonPath('events.messages', 1)
            ->assertJsonPath('events.engagement_rate', 25)
            ->assertJsonPath('events.unique_visitors', 2);
    }

    public function test_analytics_counts_failed_answers_and_their_reasons(): void
    {
        $user = User::factory()->create();
        $site = Site::factory()->create(['user_id' => $user->getKey()]);

        WidgetMessage::factory()->assistant('Something went wrong.')->create([
            'site_id' => $site->getKey(),
            'status' => MessageStatus::Failed,
            'error_reason' => 'model_timeout',
        ]);
        WidgetMessage::factory()->assistant('Answer from knowledge.')->create([
            'site_id' => $site->getKey(),
            'status' => MessageStatus::Complete,
        ]);
        WidgetMessage::factory()->assistant('General guidance.')->create([
            'site_id' => $site->getKey(),
            'status' => MessageStatus::Complete,
            'was_fallback' => true,
        ]);

        $response = $this->actingAs($user)->getJson(route('widget.analytics', $site));

        $response->assertOk()
            ->assertJsonPath('totals.failed', 1)
            ->assertJsonPath('totals.fallback', 1)
            ->assertJsonPath('totals.successful', 1)
            ->assertJsonPath('failures.0.reason', 'model_timeout');
    }

    public function test_analytics_page_renders_for_the_site_owner(): void
    {
        $user = User::factory()->create();
        $site = Site::factory()->create(['user_id' => $user->getKey()]);

        $this->actingAs($user)
            ->get(route('widget.analytics', $site))
            ->assertOk()
            ->assertSee('Activity')
            ->assertSee($site->name, false);
    }

    public function test_analytics_denies_other_tenants(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $site = Site::factory()->create(['user_id' => $owner->getKey()]);

        $this->actingAs($other)->getJson(route('widget.analytics', $site))->assertForbidden();
    }

    public function test_analytics_export_writes_an_audit_log(): void
    {
        $user = User::factory()->create();
        $site = Site::factory()->create(['user_id' => $user->getKey()]);

        $this->actingAs($user)
            ->get(route('widget.analytics.export', $site))
            ->assertOk()
            ->assertDownload();

        $this->assertDatabaseHas('widget_conversation_audit_logs', [
            'site_id' => $site->getKey(),
            'actor_id' => $user->getKey(),
            'action' => 'analytics.exported',
        ]);
    }
}
