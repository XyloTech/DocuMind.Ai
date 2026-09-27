<?php

namespace Tests\Feature;

use App\Enums\ChatRole;
use App\Enums\MessageStatus;
use App\Enums\NotificationType;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\Document;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\Ai\ChatClient;
use App\Services\RAG\EmbeddingClient;
use App\Services\RAG\VectorStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class ChatMessageTest extends TestCase
{
    use RefreshDatabase;

    public function test_sending_a_message_streams_an_answer_and_deducts_a_credit(): void
    {
        $user = User::factory()->create(['credits' => 10]);
        $document = $this->documentWithChunks('Invoices are due within thirty days of receipt.', $user);
        $chat = $this->startChat($user, $document);

        $response = $this->actingAs($user)->postJson(route('chats.messages', $chat), [
            'message' => 'When are invoices due?',
        ]);

        $response->assertOk();

        $stream = $response->streamedContent();

        $this->assertStringContainsString('event: delta', $stream);
        $this->assertStringContainsString('event: sources', $stream);
        $this->assertStringContainsString('event: done', $stream);

        $answer = $this->assistantMessage($chat);

        $this->assertSame(MessageStatus::Complete, $answer->status);
        $this->assertNotSame('', $answer->content);
        $this->assertStringContainsString('Invoices are due', $answer->content);
        $this->assertSame(1, $answer->credits_cost);
        $this->assertSame(9, $user->fresh()->credits);

        $sources = $answer->sourceBadges();

        $this->assertCount(1, $sources);
        $this->assertSame(0, $sources[0]['chunk_index']);
        $this->assertSame(2, $sources[0]['page_from']);
        $this->assertSame(3, $sources[0]['page_to']);
        $this->assertStringContainsString('Invoices are due', $sources[0]['snippet']);

        $this->assertSame(2, $chat->fresh()->message_count);
        $this->assertSame('When are invoices due?', $chat->fresh()->title);
    }

    public function test_the_stream_announces_each_phase_before_the_work_it_describes(): void
    {
        $user = User::factory()->create(['credits' => 10]);
        $document = $this->documentWithChunks('Invoices are due within thirty days of receipt.', $user);
        $chat = $this->startChat($user, $document);

        $response = $this->actingAs($user)->postJson(route('chats.messages', $chat), [
            'message' => 'When are invoices due?',
        ]);

        $response->assertOk();

        $stream = $response->streamedContent();

        $this->assertSame(['reading', 'searching', 'preparing'], $this->phases($stream));
        $this->assertLessThan(strpos($stream, 'event: delta'), strpos($stream, 'event: status'));
        $this->assertLessThan(strpos($stream, 'event: delta'), strpos($stream, '"phase":"searching"'));
        $this->assertLessThan(strpos($stream, 'event: delta'), strpos($stream, '"phase":"preparing"'));
    }

    public function test_a_greeting_announces_no_search_phase(): void
    {
        $user = User::factory()->create(['credits' => 10]);
        $document = $this->documentWithChunks('Invoices are due within thirty days of receipt.', $user);
        $chat = $this->startChat($user, $document);

        $response = $this->actingAs($user)->postJson(route('chats.messages', $chat), [
            'message' => 'Hi there!',
        ]);

        $response->assertOk();

        $this->assertSame(['reading', 'preparing'], $this->phases($response->streamedContent()));
    }

    public function test_a_user_without_credits_gets_a_402_and_nothing_is_written(): void
    {
        $user = User::factory()->create(['credits' => 0]);
        $document = $this->documentWithChunks('Invoices are due within thirty days of receipt.', $user);
        $chat = $this->startChat($user, $document);

        $this->actingAs($user)
            ->postJson(route('chats.messages', $chat), ['message' => 'Anything?'])
            ->assertStatus(402)
            ->assertJsonPath('credits', 0);

        $this->assertSame(0, ChatMessage::query()->where('chat_id', $chat->getKey())->count());
        $this->assertSame(0, $user->fresh()->credits);
    }

    public function test_when_nothing_matches_the_llm_gives_caveated_general_guidance(): void
    {
        config(['rag.min_similarity' => 1.0]);

        $user = User::factory()->create(['credits' => 10]);
        $document = $this->documentWithChunks('Invoices are due within thirty days of receipt.', $user);
        $chat = $this->startChat($user, $document);

        $response = $this->actingAs($user)->postJson(route('chats.messages', $chat), [
            'message' => 'What is the airspeed velocity of an unladen swallow?',
        ]);

        $response->assertOk();
        $stream = $response->streamedContent();
        $this->assertStringContainsString('event: delta', $stream);
        $this->assertStringContainsString('event: done', $stream);

        $answer = $this->assistantMessage($chat);

        $this->assertStringContainsString('confirmed support information', $answer->content);
        $this->assertNotSame(ChatClient::REFUSAL, $answer->content);
        $this->assertSame(MessageStatus::Complete, $answer->status);
        $this->assertSame(1, $answer->credits_cost);
        $this->assertSame('fake', $answer->model_used);
        $this->assertSame([], $answer->sourceBadges());
        $this->assertSame(9, $user->fresh()->credits);
    }

    public function test_a_greeting_is_answered_with_small_talk_instead_of_a_refusal(): void
    {
        $user = User::factory()->create(['credits' => 10]);
        $document = $this->documentWithChunks('Invoices are due within thirty days of receipt.', $user);
        $chat = $this->startChat($user, $document);

        $response = $this->actingAs($user)->postJson(route('chats.messages', $chat), [
            'message' => 'Hi there!',
        ]);

        $response->assertOk();
        $response->streamedContent();

        $answer = $this->assistantMessage($chat);

        $this->assertNotSame(ChatClient::REFUSAL, $answer->content);
        $this->assertNotSame('', $answer->content);
        $this->assertSame(MessageStatus::Complete, $answer->status);
        $this->assertSame([], $answer->sourceBadges());
        $this->assertSame(9, $user->fresh()->credits);
    }

    public function test_another_user_cannot_open_or_answer_someone_elses_chat(): void
    {
        $owner = User::factory()->create();
        $document = $this->documentWithChunks('Invoices are due within thirty days of receipt.', $owner);
        $chat = $this->startChat($owner, $document);

        $intruder = User::factory()->create(['credits' => 10]);

        $this->actingAs($intruder)->get(route('chats.show', $chat))->assertForbidden();

        $this->actingAs($intruder)
            ->postJson(route('chats.messages', $chat), ['message' => 'Tell me everything'])
            ->assertForbidden();

        $this->actingAs($intruder)->delete(route('chats.destroy', $chat))->assertForbidden();

        $this->assertSame(10, $intruder->fresh()->credits);
        $this->assertSame(0, ChatMessage::query()->where('chat_id', $chat->getKey())->count());
    }

    public function test_a_chat_cannot_be_started_on_someone_elses_document(): void
    {
        $owner = User::factory()->create();
        $document = $this->documentWithChunks('Invoices are due within thirty days of receipt.', $owner);

        $other = User::factory()->create();

        $this->actingAs($other)
            ->post(route('chats.store'), ['document_id' => $document->getKey()])
            ->assertForbidden();

        $this->assertSame(0, Chat::query()->where('user_id', $other->getKey())->count());
    }

    public function test_a_chat_cannot_be_started_before_the_document_is_processed(): void
    {
        $user = User::factory()->create();
        $document = Document::factory()->pending()->create(['user_id' => $user->getKey()]);

        $this->actingAs($user)
            ->postJson(route('chats.store'), ['document_id' => $document->getKey()])
            ->assertStatus(422)
            ->assertJsonValidationErrors('document_id');

        $this->assertSame(0, Chat::query()->where('user_id', $user->getKey())->count());
    }

    public function test_the_workspace_renders_support_knowledge_verification_without_page_numbers(): void
    {
        $user = User::factory()->create(['credits' => 10]);
        $document = $this->documentWithChunks('Invoices are due within thirty days of receipt.', $user);
        $chat = $this->startChat($user, $document);

        $streamed = $this->actingAs($user)
            ->postJson(route('chats.messages', $chat), ['message' => 'When are invoices due?']);

        $streamed->assertOk();
        $this->assertStringContainsString('event: done', $streamed->streamedContent());

        $page = $this->actingAs($user)->get(route('chats.show', $chat));

        $page->assertOk();
        $page->assertSee('When are invoices due?', false);
        $page->assertSee('Knowledge match [1]', false);
        // Retrieval internals stay out of the interface.
        $page->assertDontSee('chunk #', false);
        $page->assertDontSee('p2–3', false);
        $page->assertSee('% support match', false);
    }

    public function test_deleting_a_document_detaches_the_chat_instead_of_failing(): void
    {
        $user = User::factory()->create(['credits' => 10]);
        $document = $this->documentWithChunks('Invoices are due within thirty days of receipt.', $user);
        $chat = $this->startChat($user, $document);

        $document->delete();

        $this->assertNull($chat->fresh()->document_id);
        $this->assertFalse($chat->fresh()->isAnswerable());

        $this->actingAs($user)
            ->postJson(route('chats.messages', $chat), ['message' => 'Anything?'])
            ->assertStatus(422);
    }

    public function test_a_rejected_message_answers_json_even_for_an_sse_client(): void
    {
        $user = User::factory()->create(['credits' => 0]);
        $document = $this->documentWithChunks('Invoices are due within thirty days of receipt.', $user);
        $chat = $this->startChat($user, $document);

        // The browser client sends `Accept: text/event-stream`, for which
        // `expectsJson()` is false. A redirect here used to be parsed as an
        // empty SSE stream, so the user saw a blank bubble and lost the
        // question with no error at all.
        $response = $this->actingAs($user)->post(route('chats.messages', $chat), [
            'message' => 'Anything?',
        ], ['Accept' => 'text/event-stream']);

        $response->assertStatus(402);
        $response->assertHeader('content-type', 'application/json');
        $response->assertJsonPath('credits', 0);
    }

    public function test_an_unanswerable_chat_answers_json_even_for_an_sse_client(): void
    {
        $user = User::factory()->create(['credits' => 10]);
        $document = $this->documentWithChunks('Invoices are due within thirty days of receipt.', $user);
        $chat = $this->startChat($user, $document);

        $document->delete();

        $response = $this->actingAs($user)->post(route('chats.messages', $chat), [
            'message' => 'Anything?',
        ], ['Accept' => 'text/event-stream']);

        $response->assertStatus(422);
        $response->assertJsonPath('message', 'This chat has no ready document. Pick another document or upload a new PDF.');
    }

    public function test_a_generation_failure_does_not_leak_the_exception_message(): void
    {
        $user = User::factory()->create(['credits' => 10]);
        $document = $this->documentWithChunks('Invoices are due within thirty days of receipt.', $user);
        $chat = $this->startChat($user, $document);

        $this->mock(ChatClient::class)
            ->shouldReceive('complete')
            ->andThrow(new RuntimeException('SQLSTATE[42S02]: Base table or view not found'));

        $response = $this->actingAs($user)->postJson(route('chats.messages', $chat), [
            'message' => 'When are invoices due?',
        ]);

        $stream = $response->streamedContent();

        $this->assertStringContainsString('event: error', $stream);
        $this->assertStringNotContainsString('SQLSTATE', $stream);

        $answer = $this->assistantMessage($chat);

        $this->assertSame(MessageStatus::Failed, $answer->status);
        $this->assertSame(0, $answer->credits_cost);
        $this->assertSame(10, $user->fresh()->credits);

        $notification = UserNotification::query()
            ->where('user_id', $user->getKey())
            ->where('type', NotificationType::AiFailed->value)
            ->firstOrFail();

        $this->assertSame(NotificationType::AiFailed, $notification->type);
        $this->assertSame('ai.failed.'.$answer->getKey(), $notification->dedupe_key);
        $this->assertSame(route('chats.show', $chat), $notification->link);
    }

    public function test_a_chat_cannot_be_renamed_to_the_reserved_auto_title(): void
    {
        $user = User::factory()->create(['credits' => 10]);
        $document = $this->documentWithChunks('Invoices are due within thirty days of receipt.', $user);
        $chat = $this->startChat($user, $document);

        // Otherwise the next message silently overwrites the name the user chose.
        $this->actingAs($user)
            ->patchJson(route('chats.update', $chat), ['title' => 'New chat'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('title');
    }

    public function test_an_auto_title_cuts_on_a_word_boundary(): void
    {
        $user = User::factory()->create(['credits' => 10]);
        $document = $this->documentWithChunks('Invoices are due within thirty days of receipt.', $user);
        $chat = $this->startChat($user, $document);

        $question = 'Explain the annual subscription renewal schedule and proration rules in detail';

        $this->actingAs($user)
            ->postJson(route('chats.messages', $chat), ['message' => $question])
            ->assertOk();

        $title = $chat->fresh()->title;

        $this->assertLessThanOrEqual(61, mb_strlen($title));
        $this->assertStringEndsWith('…', $title);
        $this->assertStringEndsNotWith('subscripti…', $title);
        $this->assertSame(mb_substr($title, 0, -1), rtrim(mb_substr($title, 0, -1)));
    }

    /**
     * The phases the server announced, in the order it announced them.
     *
     * @return list<string>
     */
    private function phases(string $stream): array
    {
        preg_match_all('/event: status\r?\ndata: \{"phase":"(\w+)"\}/', $stream, $matches);

        return $matches[1];
    }

    /**
     * Build a processed document with a single embedded chunk so retrieval has
     * something real to rank against.
     */
    private function documentWithChunks(string $text, User $user): Document
    {
        $document = Document::factory()->create([
            'user_id' => $user->getKey(),
            'filename' => 'policy.pdf',
            'page_count' => 3,
            'chunk_count' => 1,
        ]);

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

        return $document;
    }

    private function startChat(User $user, Document $document): Chat
    {
        $this->actingAs($user)
            ->post(route('chats.store'), ['document_id' => $document->getKey()])
            ->assertRedirect();

        return Chat::query()
            ->where('user_id', $user->getKey())
            ->where('document_id', $document->getKey())
            ->firstOrFail();
    }

    private function assistantMessage(Chat $chat): ChatMessage
    {
        return ChatMessage::query()
            ->where('chat_id', $chat->getKey())
            ->where('role', ChatRole::Assistant->value)
            ->firstOrFail();
    }
}
