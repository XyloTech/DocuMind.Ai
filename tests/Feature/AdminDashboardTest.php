<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AdminAuditLog;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\Document;
use App\Models\Setting;
use App\Models\Site;
use App\Models\User;
use App\Models\WidgetConversation;
use App\Models\WidgetMessage;
use App\Services\Ai\ChatClient;
use App\Services\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_dashboard_renders_with_statistics(): void
    {
        User::factory()->count(3)->create();
        User::factory()->admin()->create();
        User::factory()->banned()->create();

        $response = $this->actingAs(User::factory()->admin()->create())->get(route('admin.dashboard'));

        $response->assertOk();
        $response->assertSee('Customer accounts');
        $response->assertSee('Credits in circulation');
    }

    public function test_regular_users_cannot_access_the_admin_area(): void
    {
        $response = $this->actingAs(User::factory()->create())->get(route('admin.dashboard'));

        $response->assertForbidden();
    }

    public function test_support_and_analyst_roles_can_open_read_only_admin_overview(): void
    {
        foreach (['support', 'analyst'] as $role) {
            $response = $this->actingAs(User::factory()->create(['role' => $role]))
                ->get(route('admin.dashboard'));

            $response->assertOk();
        }
    }

    public function test_support_sees_masked_account_metadata_and_cannot_mutate_users(): void
    {
        $customer = User::factory()->create(['name' => 'Customer One', 'email' => 'customer@example.com']);
        $support = User::factory()->support()->create();

        $page = $this->actingAs($support)->get(route('admin.dashboard', ['tab' => 'accounts']));

        $page->assertOk()
            ->assertSee('Account #'.$customer->getKey())
            ->assertSee('Restricted')
            ->assertDontSee($customer->email);

        $this->actingAs($support)->put(route('admin.users.update', $customer), [
            'role' => 'admin',
            'status' => 'active',
            'credits' => 100,
            'reason' => 'Attempted unauthorized change',
        ])->assertForbidden();

        $this->assertDatabaseHas('admin_audit_logs', [
            'actor_id' => $support->getKey(),
            'action' => 'accounts.viewed',
        ]);
        $this->assertSame(UserRole::User, $customer->fresh()->role);
    }

    public function test_analysts_cannot_open_person_level_admin_tabs(): void
    {
        $analyst = User::factory()->analyst()->create();

        $this->actingAs($analyst)->get(route('admin.dashboard', ['tab' => 'accounts']))->assertForbidden();
        $this->actingAs($analyst)->get(route('admin.dashboard', ['tab' => 'conversations']))->assertForbidden();
        $this->actingAs($analyst)->get(route('admin.dashboard', ['tab' => 'audit']))->assertForbidden();
    }

    public function test_conversation_operations_show_metadata_without_titles_messages_or_visitor_emails(): void
    {
        $admin = User::factory()->admin()->create();
        $owner = User::factory()->create();
        $document = Document::factory()->create(['user_id' => $owner->getKey()]);
        $chat = Chat::factory()->create([
            'user_id' => $owner->getKey(),
            'document_id' => $document->getKey(),
            'title' => 'Secret billing issue title',
        ]);
        ChatMessage::factory()->assistant('Private main-chat transcript text')->create(['chat_id' => $chat->getKey()]);

        $site = Site::factory()->create(['user_id' => $owner->getKey()]);
        $conversation = WidgetConversation::factory()->create([
            'site_id' => $site->getKey(),
            'visitor_email' => 'visitor-private@example.test',
        ]);
        WidgetMessage::factory()->assistant('Private visitor transcript text')->create([
            'site_id' => $site->getKey(),
            'widget_conversation_id' => $conversation->getKey(),
        ]);

        $page = $this->actingAs($admin)->get(route('admin.dashboard', ['tab' => 'conversations']));

        $page->assertOk()
            ->assertSee('Conversation #'.$chat->getKey())
            ->assertSee('Visitor conversation #'.$conversation->getKey())
            ->assertDontSee('Secret billing issue title')
            ->assertDontSee('Private main-chat transcript text')
            ->assertDontSee('Private visitor transcript text')
            ->assertDontSee('visitor-private@example.test');
    }

    public function test_an_administrator_can_change_account_access_and_the_reason_is_encrypted_in_the_audit_log(): void
    {
        $admin = User::factory()->admin()->create();
        $customer = User::factory()->create();

        $this->actingAs($admin)->put(route('admin.users.update', $customer), [
            'role' => 'support',
            'status' => 'suspended',
            'credits' => 45,
            'reason' => 'Customer requested account suspension',
        ])->assertRedirect();

        $updated = $customer->fresh();
        $this->assertSame(UserRole::Support, $updated->role);
        $this->assertTrue($updated->is_banned);
        $this->assertSame(45, $updated->credits);

        $audit = AdminAuditLog::query()->where('subject_user_id', $customer->getKey())->firstOrFail();
        $this->assertSame('Customer requested account suspension', $audit->details['reason']);
        $this->assertStringNotContainsString('Customer requested account suspension', (string) $audit->getRawOriginal('details'));
    }

    public function test_creating_an_account_leaves_google_as_its_only_sign_in_method(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post(route('admin.users.store'), [
            'name' => 'New Support Agent',
            'email' => 'new-agent@example.test',
            'role' => 'support',
            'credits' => 25,
            'reason' => 'Adding a support team member',
        ])->assertRedirect();

        $created = User::query()->where('email', 'new-agent@example.test')->firstOrFail();
        $this->assertSame(UserRole::Support, $created->role);
        $this->assertSame(25, $created->credits);
        $this->assertNull($created->firebase_uid);
        $this->assertNull($created->email_verified_at);
        $this->assertDatabaseHas('admin_audit_logs', [
            'actor_id' => $admin->getKey(),
            'subject_user_id' => $created->getKey(),
            'action' => 'account.created',
        ]);
    }

    public function test_model_key_is_encrypted_and_never_returned_to_the_admin_page(): void
    {
        $admin = User::factory()->admin()->create();
        $secret = 'sk-test-admin-managed-key';

        $this->actingAs($admin)->post(route('admin.model-settings.update'), [
            'ai_driver' => 'openai',
            'openai_api_key' => $secret,
            'reason' => 'Connecting the approved AI provider',
        ])->assertRedirect();

        $setting = Setting::query()->where('key', 'openai_api_key')->firstOrFail();
        $this->assertTrue($setting->is_secret);
        $this->assertStringNotContainsString($secret, json_encode($setting->value));
        $this->assertSame($secret, app(Settings::class)->getString('openai_api_key'));
        $this->assertSame('openai', app(ChatClient::class)->label());

        $this->get(route('admin.dashboard', ['tab' => 'models']))
            ->assertOk()
            ->assertDontSee($secret);
    }

    public function test_account_export_masks_emails_and_is_audited(): void
    {
        $admin = User::factory()->admin()->create();
        User::factory()->create(['email' => 'customer@example.com']);

        $response = $this->actingAs($admin)->get(route('admin.users.export'));

        $response->assertOk();
        $this->assertStringContainsString('c***@example.com', $response->streamedContent());
        $this->assertStringNotContainsString('customer@example.com', $response->streamedContent());
        $this->assertDatabaseHas('admin_audit_logs', [
            'actor_id' => $admin->getKey(),
            'action' => 'accounts.exported',
        ]);
    }

    public function test_conversation_operations_never_render_titles_messages_or_visitor_emails(): void
    {
        $admin = User::factory()->admin()->create();
        $owner = User::factory()->create();
        $document = Document::factory()->create(['user_id' => $owner->getKey()]);
        $chat = Chat::factory()->create([
            'user_id' => $owner->getKey(),
            'document_id' => $document->getKey(),
            'title' => 'Private billing conversation title',
        ]);
        ChatMessage::factory()->assistant('Private signed-in transcript text')->create(['chat_id' => $chat->getKey()]);

        $site = Site::factory()->create(['user_id' => $owner->getKey()]);
        $widgetConversation = WidgetConversation::factory()->create([
            'site_id' => $site->getKey(),
            'visitor_email' => 'private-visitor@example.test',
        ]);
        WidgetMessage::factory()->assistant('Private visitor transcript text')->create([
            'site_id' => $site->getKey(),
            'widget_conversation_id' => $widgetConversation->getKey(),
        ]);

        $page = $this->actingAs($admin)->get(route('admin.dashboard', ['tab' => 'conversations']));

        $page->assertOk()
            ->assertSee('Conversation #'.$chat->getKey())
            ->assertSee('Visitor conversation #'.$widgetConversation->getKey())
            ->assertDontSee('Private billing conversation title')
            ->assertDontSee('Private signed-in transcript text')
            ->assertDontSee('Private visitor transcript text')
            ->assertDontSee('private-visitor@example.test');
    }

    public function test_admin_operational_tabs_render_against_live_records(): void
    {
        $admin = User::factory()->admin()->create();
        $owner = User::factory()->create();
        $document = Document::factory()->create(['user_id' => $owner->getKey()]);
        $site = Site::factory()->create(['user_id' => $owner->getKey()]);
        $site->documents()->attach($document);
        WidgetConversation::factory()->create(['site_id' => $site->getKey()]);

        AdminAuditLog::query()->create([
            'actor_id' => $admin->getKey(),
            'subject_user_id' => $owner->getKey(),
            'action' => 'account.updated',
            'summary' => 'Updated account access or credits',
            'details' => ['reason' => 'Routine access review'],
            'created_at' => now(),
        ]);

        foreach (['overview', 'accounts', 'knowledge', 'conversations', 'widgets', 'models', 'audit'] as $tab) {
            $this->actingAs($admin)
                ->get(route('admin.dashboard', ['tab' => $tab]))
                ->assertOk();
        }
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get(route('admin.dashboard'))->assertRedirect(route('login'));
    }
}
