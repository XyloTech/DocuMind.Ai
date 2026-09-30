<?php

namespace Tests\Feature;

use App\Enums\ChatRole;
use App\Enums\NotificationType;
use App\Models\Document;
use App\Models\Site;
use App\Models\SupportConversation;
use App\Models\User;
use App\Models\WidgetConversation;
use App\Models\WidgetMessage;
use App\Services\RAG\EmbeddingClient;
use App\Services\RAG\VectorStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WidgetHandoffTest extends TestCase
{
    use RefreshDatabase;

    /** The visitor id the real widget keeps in localStorage. */
    private const string VISITOR = 'visitor-abcdef123456';

    public function test_asking_for_a_human_opens_a_support_conversation_in_the_widget_and_spends_no_quota(): void
    {
        $agent = User::factory()->admin()->create(['name' => 'Priya']);
        $site = $this->siteWithDocument();
        $conversation = $this->conversation($site);

        $response = $this->postJson(
            route('widget.conversations.messages', [$site->site_key, $conversation->getKey()]),
            ['message' => 'I want to speak with a human about my account.', 'visitor_id' => self::VISITOR],
        );

        $response->assertOk();

        $stream = $response->streamedContent();

        $this->assertStringContainsString('event: handoff', $stream);
        $this->assertStringContainsString('event: done', $stream);
        $this->assertStringContainsString('"last_message_id"', $stream);

        $ticket = SupportConversation::query()->firstOrFail();

        $this->assertSame($conversation->getKey(), $ticket->widget_conversation_id);
        $this->assertSame($agent->getKey(), $ticket->agent_id);
        $this->assertTrue($ticket->status->isLive());

        // The acknowledgement and the request are both free turns: the site's
        // visitor budget never moves.
        $this->assertSame(0, $site->fresh()->messagesUsedThisPeriod());
        $this->assertSame(
            0,
            (int) WidgetMessage::query()
                ->where('widget_conversation_id', $conversation->getKey())
                ->sum('credit_cost'),
        );

        $roles = $conversation->messages()
            ->orderBy('id')
            ->get()
            ->map(fn (WidgetMessage $message): string => $message->role->value)
            ->all();

        $this->assertSame(['user', 'assistant'], $roles);

        $ack = $conversation->messages()->where('role', ChatRole::Assistant->value)->firstOrFail();
        $this->assertStringContainsString('support team', $ack->content);
        $this->assertSame(0, (int) $ack->credit_cost);

        // The assigned agent hears about the request immediately.
        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $agent->getKey(),
            'type' => NotificationType::ConversationEscalated->value,
        ]);
    }

    public function test_follow_ups_land_in_the_support_inbox_and_agent_replies_come_back_through_the_widget_poll(): void
    {
        $agent = User::factory()->support()->create(['name' => 'Marco']);
        $site = $this->siteWithDocument();
        $conversation = $this->conversation($site);

        $this->postJson(
            route('widget.conversations.messages', [$site->site_key, $conversation->getKey()]),
            ['message' => 'I want to speak with a human.', 'visitor_id' => self::VISITOR],
        )->assertOk();

        $ticket = SupportConversation::query()->firstOrFail();

        // The visitor's next line goes to the human, not the model.
        $followUp = $this->postJson(
            route('widget.conversations.messages', [$site->site_key, $conversation->getKey()]),
            ['message' => 'It is the second line item on the invoice.', 'visitor_id' => self::VISITOR],
        );

        $followUp->assertOk();
        $this->assertStringContainsString('event: support', $followUp->streamedContent());
        $this->assertSame(0, $site->fresh()->messagesUsedThisPeriod());

        $this->assertDatabaseHas('support_messages', [
            'support_conversation_id' => $ticket->getKey(),
            'role' => 'user',
            'content' => 'It is the second line item on the invoice.',
        ]);

        // The agent hears about the visitor's line…
        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $agent->getKey(),
            'type' => NotificationType::ConversationReplied->value,
        ]);

        // …replies from the admin inbox…
        $this->actingAs($agent)
            ->post(route('admin.support.reply', $ticket), ['message' => 'Looking into it now.'])
            ->assertRedirect();

        // …and the widget's poll brings the reply back.
        $this->getJson(route('widget.conversations.support', [
            $site->site_key,
            $conversation->getKey(),
            'since' => 0,
            'visitor_id' => self::VISITOR,
        ]))
            ->assertOk()
            ->assertJsonPath('support.status', 'assigned')
            ->assertJsonPath('support.live', true)
            ->assertJsonFragment(['content' => 'Looking into it now.', 'role' => 'agent', 'label' => 'Marco']);
    }

    public function test_reopening_the_widget_restores_the_support_conversation_and_transcript(): void
    {
        $agent = User::factory()->admin()->create(['name' => 'Priya']);
        $site = $this->siteWithDocument();
        $conversation = $this->conversation($site);

        $this->postJson(
            route('widget.conversations.messages', [$site->site_key, $conversation->getKey()]),
            ['message' => 'I want to speak with a human.', 'visitor_id' => self::VISITOR],
        )->assertOk();

        $ticket = SupportConversation::query()->firstOrFail();

        $this->actingAs($agent)
            ->post(route('admin.support.reply', $ticket), ['message' => 'Hi! What can we help with?'])
            ->assertRedirect();

        $response = $this->getJson(
            route('widget.conversations.show', [$site->site_key, $conversation->getKey()])
                .'?visitor_id='.self::VISITOR,
        );

        $response->assertOk()
            ->assertJsonPath('support.conversation_id', $ticket->getKey())
            ->assertJsonPath('support.status', 'assigned')
            ->assertJsonPath('support.live', true)
            ->assertJsonPath('support.agent', 'Priya');

        $support = $response->json('support');
        $this->assertArrayHasKey('last_message_id', $support);
        $this->assertGreaterThan(0, $support['last_message_id']);

        $transcript = $response->json('support_messages');
        $this->assertSame(['system', 'user', 'agent'], array_column($transcript, 'role'));

        $last = end($transcript);
        $this->assertSame('Hi! What can we help with?', $last['content']);
        $this->assertSame('Priya', $last['label']);

        foreach ($transcript as $row) {
            $this->assertArrayHasKey('created_at', $row);
        }

        // Widget-side rows carry timestamps too, so the widget can merge the
        // two transcripts in time order when it replays them.
        foreach ($response->json('messages') as $row) {
            $this->assertArrayHasKey('created_at', $row);
        }
    }

    public function test_resolving_the_ticket_reaches_the_widget_and_opens_a_fresh_one_later(): void
    {
        $agent = User::factory()->admin()->create();
        $site = $this->siteWithDocument();
        $conversation = $this->conversation($site);

        $this->postJson(
            route('widget.conversations.messages', [$site->site_key, $conversation->getKey()]),
            ['message' => 'I want to speak with a human.', 'visitor_id' => self::VISITOR],
        )->assertOk();

        $ticket = SupportConversation::query()->firstOrFail();

        $this->actingAs($agent)
            ->post(route('admin.support.resolve', $ticket))
            ->assertRedirect();

        // The widget learns about the closure purely through its poll.
        $this->getJson(route('widget.conversations.support', [
            $site->site_key,
            $conversation->getKey(),
            'since' => 0,
            'visitor_id' => self::VISITOR,
        ]))
            ->assertOk()
            ->assertJsonPath('support.live', false)
            ->assertJsonPath('support.status', 'resolved')
            ->assertJsonFragment(['content' => 'Conversation resolved by '.$agent->name.'.', 'role' => 'system']);

        // A resolved ticket no longer intercepts: asking again opens a new
        // one instead of appending to the closed transcript.
        $this->postJson(
            route('widget.conversations.messages', [$site->site_key, $conversation->getKey()]),
            ['message' => 'Please connect me to support.', 'visitor_id' => self::VISITOR],
        )->assertOk();

        $this->assertSame(2, SupportConversation::query()->count());
    }

    public function test_human_support_still_works_after_the_sites_quota_is_exhausted(): void
    {
        $agent = User::factory()->admin()->create();
        $site = $this->siteWithDocument();
        $site->update(['monthly_quota' => 1]);
        $conversation = $this->conversation($site);

        // Spend the site's only unit…
        $this->postJson(
            route('widget.conversations.messages', [$site->site_key, $conversation->getKey()]),
            ['message' => 'When are invoices due?', 'visitor_id' => self::VISITOR],
        )->assertOk();

        $this->assertSame(1, $site->fresh()->messagesUsedThisPeriod());

        // …a normal question is refused…
        $this->postJson(
            route('widget.conversations.messages', [$site->site_key, $conversation->getKey()]),
            ['message' => 'And refunds?', 'visitor_id' => self::VISITOR],
        )->assertStatus(429)->assertJsonPath('exhausted', true);

        // …but asking for a person still opens the conversation.
        $this->postJson(
            route('widget.conversations.messages', [$site->site_key, $conversation->getKey()]),
            ['message' => 'Talk to someone who can help me.', 'visitor_id' => self::VISITOR],
        )->assertOk();

        $this->assertSame(1, SupportConversation::query()->count());
        $this->assertSame($agent->getKey(), SupportConversation::query()->firstOrFail()->agent_id);

        // Follow-ups keep flowing to the agent even though quota is gone.
        $followUp = $this->postJson(
            route('widget.conversations.messages', [$site->site_key, $conversation->getKey()]),
            ['message' => 'Are you there?', 'visitor_id' => self::VISITOR],
        );

        $followUp->assertOk();
        $this->assertStringContainsString('event: support', $followUp->streamedContent());
        $this->assertSame(1, $site->fresh()->messagesUsedThisPeriod());
    }

    public function test_handoff_works_before_email_consent_while_normal_messages_wait_for_it(): void
    {
        User::factory()->admin()->create();
        $site = $this->siteWithDocument();
        $site->update(['collect_email' => true]);
        $conversation = $this->conversation($site);

        $this->postJson(
            route('widget.conversations.messages', [$site->site_key, $conversation->getKey()]),
            ['message' => 'Hello?', 'visitor_id' => self::VISITOR],
        )->assertForbidden();

        $this->postJson(
            route('widget.conversations.messages', [$site->site_key, $conversation->getKey()]),
            ['message' => 'I want to speak with a human.', 'visitor_id' => self::VISITOR],
        )->assertOk();

        $this->assertSame(1, SupportConversation::query()->count());

        $conversation->refresh();
        $this->assertNull($conversation->visitor_email_consent_at);
    }

    public function test_a_negated_request_stays_with_the_assistant(): void
    {
        $site = $this->siteWithDocument();
        $conversation = $this->conversation($site);

        $this->postJson(
            route('widget.conversations.messages', [$site->site_key, $conversation->getKey()]),
            ['message' => "I don't want to speak with a human, just answer from the document.", 'visitor_id' => self::VISITOR],
        )->assertOk();

        $this->assertSame(0, SupportConversation::query()->count());
    }

    public function test_a_visitor_cannot_poll_another_conversations_support_thread(): void
    {
        User::factory()->admin()->create();
        $site = $this->siteWithDocument();
        $conversation = $this->conversation($site);

        $this->postJson(
            route('widget.conversations.messages', [$site->site_key, $conversation->getKey()]),
            ['message' => 'I want to speak with a human.', 'visitor_id' => self::VISITOR],
        )->assertOk();

        $this->getJson(
            route('widget.conversations.support', [$site->site_key, $conversation->getKey()])
                .'?since=0&visitor_id=visitor-someoneelse99',
        )->assertNotFound();
    }

    private function conversation(Site $site): WidgetConversation
    {
        return WidgetConversation::create([
            'site_id' => $site->getKey(),
            'visitor_id' => self::VISITOR,
        ]);
    }

    private function siteWithDocument(): Site
    {
        $user = User::factory()->create();
        $document = $this->document($user, 'Invoices are due within thirty days of receipt. Refunds take fourteen days.');

        $site = Site::factory()->create(['user_id' => $user->getKey()]);
        $site->documents()->attach($document);

        return $site;
    }

    private function document(User $user, string $text): Document
    {
        $document = Document::factory()->create(['user_id' => $user->getKey()]);
        $this->persist($document, $text);

        return $document;
    }

    private function persist(Document $document, string $text): void
    {
        app(VectorStore::class)->persist($document, [[
            'chunk_index' => 0,
            'text' => $text,
            'page_from' => 1,
            'page_to' => 1,
            'char_start' => 0,
            'char_end' => mb_strlen($text),
            'token_estimate' => (int) ceil(mb_strlen($text) / 4),
            'content_hash' => sha1($text),
            'vector' => app(EmbeddingClient::class)->embedOne($text),
        ]]);
    }
}
