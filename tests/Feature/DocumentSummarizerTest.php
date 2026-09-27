<?php

namespace Tests\Feature;

use App\Exceptions\ChatException;
use App\Models\Document;
use App\Models\User;
use App\Services\Ai\ChatClient;
use App\Services\Ai\ChatCompletion;
use App\Services\RAG\EmbeddingClient;
use App\Services\RAG\VectorStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DocumentSummarizerTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_endpoint_returns_a_summary_and_spends_one_credit(): void
    {
        $user = User::factory()->create(['credits' => 5]);
        $document = $this->documentWithChunks($user, 'Invoices are due within thirty days of receipt.');

        $this->actingAs($user)
            ->postJson(route('documents.summarize', $document))
            ->assertOk()
            ->assertJsonPath('credits', 4)
            ->assertJsonStructure(['summary', 'credits']);

        $this->assertSame(4, $user->fresh()->credits);
    }

    public function test_a_summary_refunds_the_credit_when_the_model_fails(): void
    {
        $this->app->instance(ChatClient::class, new class implements ChatClient
        {
            public function label(): string
            {
                return 'broken';
            }

            public function complete(array $messages, ?callable $onDelta = null): ChatCompletion
            {
                throw new ChatException('model exploded');
            }
        });

        $user = User::factory()->create(['credits' => 5]);
        $document = $this->documentWithChunks($user, 'Invoices are due within thirty days of receipt.');

        $this->actingAs($user)
            ->postJson(route('documents.summarize', $document))
            ->assertStatus(502)
            // The internal exception message must not reach the browser.
            ->assertJsonPath('message', 'The summary could not be generated. Your credit was refunded.')
            ->assertJsonMissingPath('errors');

        $this->assertSame(5, $user->fresh()->credits);
    }

    public function test_an_unprocessed_document_cannot_be_summarised(): void
    {
        $user = User::factory()->create(['credits' => 5]);
        $document = Document::factory()->pending()->create(['user_id' => $user->getKey()]);

        $this->actingAs($user)
            ->postJson(route('documents.summarize', $document))
            ->assertStatus(422);

        $this->assertSame(5, $user->fresh()->credits);
    }

    public function test_a_user_without_credits_gets_a_402(): void
    {
        $user = User::factory()->create(['credits' => 0]);
        $document = $this->documentWithChunks($user, 'Invoices are due within thirty days of receipt.');

        $this->actingAs($user)
            ->postJson(route('documents.summarize', $document))
            ->assertStatus(402);
    }

    public function test_another_user_cannot_summarise_or_read_suggestions(): void
    {
        $owner = User::factory()->create(['credits' => 5]);
        $document = $this->documentWithChunks($owner, 'Invoices are due within thirty days of receipt.');
        $intruder = User::factory()->create(['credits' => 5]);

        $this->actingAs($intruder)->postJson(route('documents.summarize', $document))->assertForbidden();
        $this->actingAs($intruder)->getJson(route('documents.suggestions', $document))->assertForbidden();

        $this->assertSame(5, $intruder->fresh()->credits);
    }

    public function test_suggestions_are_exposed_for_the_owner(): void
    {
        $user = User::factory()->create();
        $document = $this->documentWithChunks($user, '1. Executive Summary Schools coordinate activities.');

        $this->actingAs($user)
            ->getJson(route('documents.suggestions', $document))
            ->assertOk()
            ->assertJsonStructure(['questions']);
    }

    public function test_the_summary_is_built_from_map_reduce_over_slices(): void
    {
        config(['rag.summary_slices' => 2]);

        $recorder = new RecordingChatClient;

        $this->app->instance(ChatClient::class, $recorder);

        $user = User::factory()->create(['credits' => 5]);
        $document = $this->documentWithChunks($user, [
            'Invoices are due within thirty days of receipt.',
            'Refunds are processed within fourteen days of a written request.',
        ]);

        $this->actingAs($user)->postJson(route('documents.summarize', $document))->assertOk();

        // One prompt per slice, then a final prompt combining the notes.
        $this->assertCount(3, $recorder->prompts);
        $this->assertStringContainsString('Excerpt from', $recorder->prompts[0]);
        $this->assertStringContainsString('Excerpt from', $recorder->prompts[1]);
        $this->assertStringContainsString('Notes taken from', $recorder->prompts[2]);
    }

    /**
     * @param  list<string>  $texts
     */
    private function documentWithChunks(User $user, array|string $texts): Document
    {
        $texts = (array) $texts;

        $document = Document::factory()->create([
            'user_id' => $user->getKey(),
            'suggested_questions' => ['What does this document say about invoices?'],
        ]);

        $vectors = app(EmbeddingClient::class)->embed($texts);

        app(VectorStore::class)->persist($document, array_map(
            fn (string $text, int $index): array => [
                'chunk_index' => $index,
                'text' => $text,
                'page_from' => $index + 1,
                'page_to' => $index + 1,
                'char_start' => 0,
                'char_end' => mb_strlen($text),
                'token_estimate' => (int) ceil(mb_strlen($text) / 4),
                'content_hash' => sha1($text),
                'vector' => $vectors[$index],
            ],
            $texts,
            array_keys($texts),
        ));

        return $document;
    }
}

class RecordingChatClient implements ChatClient
{
    /**
     * @var list<string>
     */
    public array $prompts = [];

    public function label(): string
    {
        return 'recording';
    }

    public function complete(array $messages, ?callable $onDelta = null): ChatCompletion
    {
        $this->prompts[] = (string) end($messages)['content'];

        return new ChatCompletion('A short summary.', 'recording');
    }
}
