<?php

namespace Tests\Feature;

use App\Models\SupportConversation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminSupportInboxTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_support_inbox_lists_conversations_for_admin_and_support_only(): void
    {
        $conversation = SupportConversation::factory()->create();

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->get(route('admin.dashboard', ['tab' => 'support']))
            ->assertOk()
            ->assertSee('Support conversation #'.$conversation->getKey());

        $support = User::factory()->support()->create();
        $this->actingAs($support)->get(route('admin.dashboard', ['tab' => 'support']))->assertOk();

        $analyst = User::factory()->analyst()->create();
        $this->actingAs($analyst)->get(route('admin.dashboard', ['tab' => 'support']))->assertForbidden();

        $customer = User::factory()->create();
        $this->actingAs($customer)->get(route('admin.dashboard', ['tab' => 'support']))->assertForbidden();
    }

    public function test_the_inbox_tab_never_renders_the_transcript(): void
    {
        $conversation = SupportConversation::factory()->create();
        $conversation->messages()->create([
            'sender_id' => $conversation->user_id,
            'role' => 'user',
            'content' => 'Private support transcript text',
        ]);

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('admin.dashboard', ['tab' => 'support']))
            ->assertOk()
            ->assertSee('Support conversation #'.$conversation->getKey())
            ->assertDontSee('Private support transcript text');
    }

    public function test_a_support_agent_can_open_the_transcript_and_reply(): void
    {
        $support = User::factory()->support()->create();
        $customer = User::factory()->create();
        $conversation = SupportConversation::factory()->create([
            'user_id' => $customer->getKey(),
            'status' => 'assigned',
            'agent_id' => $support->getKey(),
        ]);
        $conversation->messages()->create([
            'sender_id' => $customer->getKey(),
            'role' => 'user',
            'content' => 'The export button fails on Safari.',
        ]);

        $this->actingAs($support)->get(route('admin.support.show', $conversation))
            ->assertOk()
            ->assertSee('The export button fails on Safari.');

        $this->actingAs($support)->post(route('admin.support.reply', $conversation), [
            'message' => 'Thanks — we are shipping a fix today.',
        ])->assertRedirect();

        $this->assertDatabaseHas('support_messages', [
            'support_conversation_id' => $conversation->getKey(),
            'role' => 'agent',
            'content' => 'Thanks — we are shipping a fix today.',
        ]);

        $this->assertDatabaseHas('admin_audit_logs', [
            'actor_id' => $support->getKey(),
            'subject_user_id' => $customer->getKey(),
            'action' => 'support.replied',
        ]);
    }

    public function test_analysts_and_customers_cannot_open_a_support_transcript(): void
    {
        $conversation = SupportConversation::factory()->create();

        $analyst = User::factory()->analyst()->create();
        $this->actingAs($analyst)->get(route('admin.support.show', $conversation))->assertForbidden();

        $customer = User::factory()->create();
        $this->actingAs($customer)->get(route('admin.support.show', $conversation))->assertForbidden();
    }

    public function test_resolving_a_conversation_closes_it_and_notifies_the_customer(): void
    {
        $admin = User::factory()->admin()->create();
        $customer = User::factory()->create();
        $conversation = SupportConversation::factory()->create([
            'user_id' => $customer->getKey(),
            'agent_id' => $admin->getKey(),
            'status' => 'assigned',
        ]);

        $this->actingAs($admin)
            ->post(route('admin.support.resolve', $conversation))
            ->assertRedirect();

        $this->assertSame('resolved', $conversation->fresh()->status->value);
        $this->assertNotNull($conversation->fresh()->resolved_at);

        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $customer->getKey(),
            'type' => 'conversation.replied',
        ]);

        $this->assertDatabaseHas('admin_audit_logs', [
            'actor_id' => $admin->getKey(),
            'action' => 'support.resolved',
        ]);
    }

    public function test_a_resolved_conversation_refuses_further_replies(): void
    {
        $admin = User::factory()->admin()->create();
        $conversation = SupportConversation::factory()->resolved()->create();

        $this->actingAs($admin)
            ->post(route('admin.support.reply', $conversation), ['message' => 'One more thing…'])
            ->assertStatus(409);
    }

    public function test_regular_users_cannot_reply_in_the_admin_inbox(): void
    {
        $conversation = SupportConversation::factory()->create();
        $customer = User::factory()->create();

        $this->actingAs($customer)
            ->post(route('admin.support.reply', $conversation), ['message' => 'Let me in'])
            ->assertForbidden();
    }
}
