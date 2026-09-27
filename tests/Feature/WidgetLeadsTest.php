<?php

namespace Tests\Feature;

use App\Enums\ChatRole;
use App\Models\Site;
use App\Models\User;
use App\Models\WidgetConversation;
use App\Models\WidgetConversationAuditLog;
use App\Models\WidgetMessage;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class WidgetLeadsTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_authorized_site_owners_can_view_visitor_email_and_transcript(): void
    {
        [$owner, $site, $conversation] = $this->conversationWithTranscript();
        $other = User::factory()->create();

        $this->actingAs($owner)
            ->get(route('widget.leads.index', $site))
            ->assertOk()
            ->assertSee('visitor@example.com')
            ->assertSee('I need help with billing.')
            ->assertSee('Consent recorded')
            ->assertSee('Email collection enabled');

        $this->actingAs($other)
            ->get(route('widget.leads.index', $site))
            ->assertForbidden()
            ->assertDontSee('visitor@example.com');

        $this->assertNotNull($conversation->fresh()->visitor_email_consent_at);
    }

    public function test_the_inbox_searches_by_exact_email_hash_without_storing_plaintext(): void
    {
        [$owner, $site, $conversation] = $this->conversationWithTranscript();
        WidgetConversation::factory()->create([
            'site_id' => $site->getKey(),
            'visitor_id' => 'visitor-second0001',
            'visitor_email' => 'another@example.com',
            'visitor_email_hash' => hash_hmac('sha256', 'another@example.com', (string) config('app.key')),
            'visitor_email_consent_at' => now(),
        ]);

        $response = $this->actingAs($owner)->get(route('widget.leads.index', [
            'site' => $site,
            'search' => 'visitor@example.com',
        ]));

        $response->assertOk()->assertSee('visitor@example.com')->assertDontSee('another@example.com');
        $this->assertNotSame('visitor@example.com', $conversation->getRawOriginal('visitor_email'));
        $this->assertNotSame('visitor-session0001', $conversation->getRawOriginal('visitor_id'));
        $this->assertSame(hash_hmac('sha256', 'visitor-session0001', (string) config('app.key')), $conversation->visitor_id_hash);
        $this->assertNotSame('I need help with billing.', $conversation->messages()->firstOrFail()->getRawOriginal('content'));
    }

    public function test_authorized_users_can_update_assign_export_and_delete_visitor_records_with_audit(): void
    {
        [$owner, $site, $conversation] = $this->conversationWithTranscript();
        $workspace = Workspace::factory()->for($owner, 'owner')->create();
        $workspace->members()->attach($owner->getKey(), ['role' => 'owner']);
        $assignee = User::factory()->create();
        $workspace->members()->attach($assignee->getKey(), ['role' => 'support']);

        $site->update(['workspace_id' => $workspace->getKey()]);
        $conversation->update(['workspace_id' => $workspace->getKey()]);

        $this->actingAs($owner)->patch(route('widget.leads.update', [$site, $conversation]), [
            'status' => 'closed',
            'classification' => 'lead',
            'assigned_user_id' => $assignee->getKey(),
            'follow_up_status' => 'contacted',
        ])->assertRedirect();

        $this->assertSame('closed', $conversation->fresh()->status);
        $this->assertSame('lead', $conversation->fresh()->classification);
        $this->assertSame($assignee->getKey(), $conversation->fresh()->assigned_user_id);
        $this->assertSame('contacted', $conversation->fresh()->follow_up_status);
        $this->assertDatabaseHas('widget_conversation_audit_logs', [
            'site_id' => $site->getKey(),
            'conversation_id' => $conversation->getKey(),
            'actor_id' => $owner->getKey(),
            'action' => 'visitor.updated',
        ]);

        $export = $this->actingAs($owner)->get(route('widget.leads.export', $site));
        $export->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->assertStringContainsString('visitor@example.com', $export->streamedContent());
        $this->assertStringContainsString('I need help with billing.', $export->streamedContent());
        $this->assertDatabaseHas('widget_conversation_audit_logs', [
            'site_id' => $site->getKey(),
            'action' => 'visitor.exported',
        ]);

        $this->delete(route('widget.leads.destroy', [$site, $conversation]))->assertRedirect();

        $this->assertDatabaseMissing('widget_conversations', ['id' => $conversation->getKey()]);
        $this->assertDatabaseHas('widget_conversation_audit_logs', [
            'site_id' => $site->getKey(),
            'action' => 'visitor.deleted',
        ]);
    }

    public function test_polling_returns_only_the_authorized_site_fragment(): void
    {
        [$owner, $site] = $this->conversationWithTranscript();

        $this->actingAs($owner)
            ->getJson(route('widget.leads.index', $site))
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonStructure(['html', 'totals' => ['conversations', 'with_email', 'open', 'follow_up', 'leads'], 'updated_at'])
            ->assertJsonPath('totals.with_email', 1);
    }

    public function test_expired_visitor_conversations_are_deleted_and_audited(): void
    {
        $this->travelTo(now()->startOfDay());
        [$owner, $site, $expired] = $this->conversationWithTranscript();
        $site->update(['visitor_retention_days' => 30]);
        $expired->forceFill(['created_at' => now()->subDays(31)])->save();
        $recent = WidgetConversation::factory()->create([
            'site_id' => $site->getKey(),
            'workspace_id' => $site->workspace_id,
            'visitor_id' => 'visitor-recent0001',
            'visitor_email' => 'recent@example.com',
            'visitor_email_hash' => hash_hmac('sha256', 'recent@example.com', (string) config('app.key')),
            'visitor_email_consent_at' => now(),
        ]);

        Artisan::call('widget:prune-visitors');

        $this->assertDatabaseMissing('widget_conversations', ['id' => $expired->getKey()]);
        $this->assertDatabaseHas('widget_conversations', ['id' => $recent->getKey()]);
        $this->assertDatabaseHas('widget_conversation_audit_logs', [
            'site_id' => $site->getKey(),
            'action' => 'visitor.retention_expired',
        ]);
    }

    /** @return array{User, Site, WidgetConversation} */
    private function conversationWithTranscript(): array
    {
        $owner = User::factory()->create();
        $workspace = Workspace::factory()->for($owner, 'owner')->create();
        $workspace->members()->attach($owner->getKey(), ['role' => 'owner']);
        $site = Site::factory()->create([
            'user_id' => $owner->getKey(),
            'workspace_id' => $workspace->getKey(),
            'collect_email' => true,
        ]);
        $conversation = WidgetConversation::factory()->create([
            'site_id' => $site->getKey(),
            'workspace_id' => $workspace->getKey(),
            'visitor_id' => 'visitor-session0001',
            'visitor_email' => 'visitor@example.com',
            'visitor_email_hash' => hash_hmac('sha256', 'visitor@example.com', (string) config('app.key')),
            'visitor_email_consent_at' => now(),
            'message_count' => 2,
        ]);
        WidgetMessage::factory()->create([
            'site_id' => $site->getKey(),
            'widget_conversation_id' => $conversation->getKey(),
            'role' => ChatRole::User,
            'content' => 'I need help with billing.',
        ]);
        WidgetMessage::factory()->assistant('Our billing team can help.')->create([
            'site_id' => $site->getKey(),
            'widget_conversation_id' => $conversation->getKey(),
        ]);

        return [$owner, $site, $conversation];
    }
}