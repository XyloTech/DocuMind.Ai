<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\User;
use App\Services\RAG\EmbeddingClient;
use App\Services\RAG\Retriever;
use App\Services\RAG\VectorStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RetrieverTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_rare_literal_term_surfaces_its_chunk_and_keeps_the_term_visible(): void
    {
        $document = $this->documentWith([
            ['index' => 0, 'text' => 'The office is open from nine to five on weekdays.'],
            [
                'index' => 1,
                'text' => str_repeat('Routine configuration values are stored centrally. ', 12)
                    .'Payments are processed through Razorpay. '
                    .str_repeat('Additional notes are appended here. ', 12),
            ],
        ]);

        $hits = app(Retriever::class)->retrieve($document, 'Razorpay');

        $this->assertNotEmpty($hits, 'the rare term should produce a hit');
        $this->assertSame(1, $hits[0]['chunk_index']);
        $this->assertStringContainsString('Razorpay', $hits[0]['snippet']);
    }

    public function test_a_paraphrased_question_matches_by_shared_vocabulary(): void
    {
        // The test driver hashes words, so "paraphrase" is expressed as a
        // question that reuses the chunk's words in a different order — the
        // vector half still has to pull that chunk to the top.
        $document = $this->documentWith([
            ['index' => 0, 'text' => 'Invoices are due within thirty days of receipt.'],
            ['index' => 1, 'text' => 'The office is open from nine to five on weekdays.'],
        ]);

        $hits = app(Retriever::class)->retrieve($document, 'when are invoices due for receipt');

        $this->assertNotEmpty($hits);
        $this->assertSame(0, $hits[0]['chunk_index']);
    }

    public function test_a_question_with_nothing_in_common_returns_no_hits(): void
    {
        $document = $this->documentWith([
            ['index' => 0, 'text' => 'Invoices are due within thirty days of receipt.'],
        ]);

        $this->assertSame([], app(Retriever::class)->retrieve($document, 'zeppelin motorcycles and bananas'));
    }

    public function test_an_unprocessed_document_returns_no_hits(): void
    {
        $user = User::factory()->create();
        $document = Document::factory()->pending()->create(['user_id' => $user->getKey()]);

        $this->assertSame([], app(Retriever::class)->retrieve($document, 'anything'));
    }

    public function test_hits_are_capped_by_top_k(): void
    {
        config(['rag.top_k' => 2]);

        $document = $this->documentWith([
            ['index' => 0, 'text' => 'Invoices are due within thirty days of receipt.'],
            ['index' => 1, 'text' => 'Refunds are issued within fourteen days of a request.'],
            ['index' => 2, 'text' => 'Invoices can also be paid by bank transfer on request.'],
        ]);

        $this->assertLessThanOrEqual(2, count(app(Retriever::class)->retrieve($document, 'invoice payment')));
    }

    public function test_disabling_hybrid_falls_back_to_vectors_only(): void
    {
        config(['rag.hybrid' => false]);

        $document = $this->documentWith([
            ['index' => 0, 'text' => 'Invoices are due within thirty days of receipt.'],
            ['index' => 1, 'text' => 'The office is open from nine to five on weekdays.'],
        ]);

        $hits = app(Retriever::class)->retrieve($document, 'when are invoices due for receipt');

        $this->assertNotEmpty($hits);
        $this->assertSame(0, $hits[0]['chunk_index']);

        foreach ($hits as $hit) {
            $this->assertArrayNotHasKey('keyword_score', $hit);
        }
    }

    /**
     * @param  list<array{index: int, text: string}>  $chunks
     */
    private function documentWith(array $chunks): Document
    {
        $user = User::factory()->create();
        $document = Document::factory()->create(['user_id' => $user->getKey()]);

        $vectors = app(EmbeddingClient::class)->embed(array_column($chunks, 'text'));

        app(VectorStore::class)->persist($document, array_map(
            fn (array $chunk, int $position): array => [
                'chunk_index' => $chunk['index'],
                'text' => $chunk['text'],
                'page_from' => 1,
                'page_to' => 1,
                'char_start' => 0,
                'char_end' => mb_strlen($chunk['text']),
                'token_estimate' => (int) ceil(mb_strlen($chunk['text']) / 4),
                'content_hash' => sha1($chunk['text']),
                'vector' => $vectors[$position],
            ],
            $chunks,
            array_keys($chunks),
        ));

        return $document;
    }
}
