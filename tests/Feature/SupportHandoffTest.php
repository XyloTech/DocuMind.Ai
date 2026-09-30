<?php

namespace Tests\Feature;

use App\Enums\ChatRole;
use App\Enums\MessageStatus;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\Document;
use App\Models\SupportConversation;
use App\Models\User;
use App\Services\RAG\EmbeddingClient;
use App\Services\RAG\VectorStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupportHandoffTest extends TestCase
{
    use RefreshDatabase;

    public function test_asking_for_a_human_opens_a_conversation_and_charges_no_credit(): void
    {
        $user = User::factory()->create(['credits' => 10]);
        $agent = User::factory()->admin()->create(['name' => 'Priya']);
        $chat = $this->startChat($user);

        $response = $this->actingAs($user)->postJson(route('chats.messages', $chat), [
            'message' => 'I want to speak with a human about my account.',
        ]);

        $response->assertOk();

        $stream = $response->streamedContent();

        $this->assertStringContainsString('event: handoff', $stream);
        $this->assertStringContainsString('event: done', $stream);
        $this->assertStringContainsString('/support', $stream);

        $this->assertSame(10, $user->fresh()->credits);

        $conversation = SupportConversation::query()->where('user_id', $user->getKey())->firstOrFail();

        $this->assertSame($agent->getKey(), $conversation->agent_id);
        $this->assertSame($chat->getKey(), $conversation->chat_id);
        $this->assertTrue($conversation->status->isLive());

        $this->assertDatabaseHas('support_messages', [
            'support_conversation_id' => $conversation->getKey(),
            'role' => 'user',
        ]);

        $answer = ChatMessage::query()
            ->where('chat_id', $chat->getKey())
            ->where('role', ChatRole::Assistant->value)
            ->firstOrFail();

        $this->assertSame(MessageStatus::Complete, $answer->status);
        $this->assertSame(0, $answer->credits_cost);
        $this->assertStringContainsString('support team', $answer->content);

        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $agent->getKey(),
            'type' => 'conversation.escalated',
        ]);
    }

    public function test_the_handoff_event_reports_where_to_continue_when_streaming_is_off(): void
    {
        config(['rag.stream_enabled' => false]);

        $user = User::factory()->create(['credits' => 10]);
        User::factory()->support()->create();
        $chat = $this->startChat($user);

        $response = $this->actingAs($user)->postJson(route('chats.messages', $chat), [
            'message' => 'Can I talk to a real person?',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['handoff' => ['conversation_id', 'status', 'agent', 'url']])
            ->assertJsonPath('handoff.status', 'assigned');

        $this->assertSame(10, $user->fresh()->credits);
        $this->assertSame(1, SupportConversation::query()->count());
    }

    public function test_a_negated_request_goes_to_the_model_instead(): void
    {
        $user = User::factory()->create(['credits' => 10]);
        $chat = $this->startChat($user);

        $this->actingAs($user)->postJson(route('chats.messages', $chat), [
            'message' => "I don't want to speak with a human, just answer from the document.",
        ])->assertOk();

        $this->assertSame(0, SupportConversation::query()->count());
        $this->assertSame(9, $user->fresh()->credits);
    }

    public function test_the_conversation_stays_open_when_no_agent_is_available(): void
    {
        $user = User::factory()->create(['credits' => 10]);
        $chat = $this->startChat($user);

        $this->actingAs($user)->postJson(route('chats.messages', $chat), [
            'message' => 'Please connect me to support.',
        ])->assertOk();

        $conversation = SupportConversation::query()->firstOrFail();

        $this->assertNull($conversation->agent_id);
        $this->assertSame('open', $conversation->status->value);
    }

    public function test_a_handoff_works_even_when_the_chat_document_is_gone(): void
    {
        $user = User::factory()->create(['credits' => 10]);
        $chat = Chat::factory()->orphaned()->create(['user_id' => $user->getKey()]);
        User::factory()->support()->create();

        $this->actingAs($user)->postJson(route('chats.messages', $chat), [
            'message' => 'Talk to someone who can help me.',
        ])->assertOk();

        $this->assertSame(1, SupportConversation::query()->count());
    }

    public function test_the_support_page_shows_only_the_signed_in_customers_conversation(): void
    {
        $owner = User::factory()->create();
        User::factory()->admin()->create();
        $conversation = SupportConversation::factory()->create(['user_id' => $owner->getKey()]);
        $conversation->messages()->create([
            'sender_id' => $owner->getKey(),
            'role' => 'user',
            'content' => 'My invoice line item is wrong.',
        ]);

        $this->actingAs($owner)->get(route('support.index'))
            ->assertOk()
            ->assertSee('My invoice line item is wrong.');

        $intruder = User::factory()->create();

        $this->actingAs($intruder)->get(route('support.index'))
            ->assertOk()
            ->assertDontSee('My invoice line item is wrong.');

        $this->actingAs($intruder)->postJson(route('support.close'))->assertNotFound();
    }

    public function test_messages_sent_from_the_support_page_reach_the_agent_and_the_reply_comes_back(): void
    {
        $user = User::factory()->create();
        $agent = User::factory()->support()->create(['name' => 'Marco']);

        $this->actingAs($user)->postJson(route('support.messages'), [
            'message' => 'My invoice looks wrong.',
        ])->assertOk()->assertJsonPath('conversation.status', 'assigned');

        $conversation = SupportConversation::query()->firstOrFail();

        $this->actingAs($user)->postJson(route('support.messages'), [
            'message' => 'It is the second line item on the invoice.',
        ])->assertOk();

        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $agent->getKey(),
            'type' => 'conversation.replied',
        ]);

        $this->actingAs($agent)
            ->post(route('admin.support.reply', $conversation), ['message' => 'Looking into it now.'])
            ->assertRedirect();

        $this->assertDatabaseHas('support_messages', [
            'support_conversation_id' => $conversation->getKey(),
            'role' => 'agent',
            'content' => 'Looking into it now.',
        ]);

        $this->actingAs($user)->getJson(route('support.poll', ['since' => 0]))
            ->assertOk()
            ->assertJsonFragment(['content' => 'Looking into it now.']);

        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $user->getKey(),
            'type' => 'conversation.replied',
        ]);
    }

    public function test_the_customer_can_close_their_conversation(): void
    {
        $user = User::factory()->create();
        User::factory()->admin()->create();
        SupportConversation::factory()->create(['user_id' => $user->getKey()]);

        $this->actingAs($user)->postJson(route('support.close'))
            ->assertOk()
            ->assertJsonPath('conversation.status', 'resolved');

        $this->assertSame('resolved', SupportConversation::query()->firstOrFail()->status->value);
    }

    /**
     * Build a processed document with one embedded chunk, then open a chat
     * about it as the given user.
     */
    private function startChat(User $user): Chat
    {
        $document = Document::factory()->create([
            'user_id' => $user->getKey(),
            'filename' => 'policy.pdf',
            'page_count' => 3,
            'chunk_count' => 1,
        ]);

        $text = 'Invoices are due within thirty days of receipt.';

        app(VectorStore::class)->persist($document, [[
            'chunk_index' => 0,
            'text' => $text,
            'page_from' => 2,
            'page_to' => 3,
            'char_start' => 0,
            'char_end' => mb_strlen($text),
            'token_estimate' => (int) ceil(mb_strlen($text) / 4),
            'content_hash' => sha1($text),
            'vector' => app(EmbeddingClient::class)->embedOne($text),
        ]]);

        $this->actingAs($user)
            ->post(route('chats.store'), ['document_id' => $document->getKey()])
            ->assertRedirect();

        return Chat::query()
            ->where('user_id', $user->getKey())
            ->where('document_id', $document->getKey())
            ->firstOrFail();
    }
}
