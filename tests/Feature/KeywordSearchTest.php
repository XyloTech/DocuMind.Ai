<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\User;
use App\Services\RAG\EmbeddingClient;
use App\Services\RAG\KeywordSearch;
use App\Services\RAG\VectorStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class KeywordSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_literal_term_ranks_its_own_chunk_first(): void
    {
        $document = $this->documentWith([
            ['text' => 'Invoices are due within thirty days of receipt.', 'index' => 0],
            ['text' => 'Firestore rules are reviewed every quarter by the team.', 'index' => 1],
            ['text' => 'The office is open from nine to five on weekdays.', 'index' => 2],
        ]);

        $hits = app(KeywordSearch::class)->search($document->getKey(), 'Firestore rules');

        $this->assertNotEmpty($hits);
        $this->assertSame(1, $hits[0]['chunk_index']);
    }

    public function test_preloaded_chunks_avoid_a_second_read_and_score_identically(): void
    {
        $document = $this->documentWith([
            ['text' => 'Invoices are due within thirty days of receipt.', 'index' => 0],
            ['text' => 'Firestore rules are reviewed every quarter by the team.', 'index' => 1],
            ['text' => 'The office is open from nine to five on weekdays.', 'index' => 2],
        ]);

        $search = app(KeywordSearch::class);
        $chunks = app(VectorStore::class)->loadForDocument($document->getKey());

        $queries = [];

        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $reused = $search->search($document->getKey(), 'Firestore rules', 25, $chunks);

        $chunkReads = array_values(array_filter(
            $queries,
            static fn (string $sql): bool => str_contains($sql, 'document_chunks'),
        ));

        $this->assertSame([], $chunkReads, 'preloaded chunks must not be read from the database again');
        $this->assertSame($search->search($document->getKey(), 'Firestore rules'), $reused);
    }

    public function test_a_query_matching_nothing_returns_nothing(): void
    {
        $document = $this->documentWith([
            ['text' => 'Invoices are due within thirty days of receipt.', 'index' => 0],
        ]);

        $this->assertSame([], app(KeywordSearch::class)->search($document->getKey(), 'zzzz qqqq'));
    }

    public function test_a_term_present_in_every_chunk_carries_no_signal(): void
    {
        $document = $this->documentWith([
            ['text' => 'The policy covers every payment method we support today.', 'index' => 0],
            ['text' => 'The policy also covers refunds and subscription changes.', 'index' => 1],
            ['text' => 'The policy is reviewed by the team each quarter.', 'index' => 2],
            ['text' => 'The policy applies to all active subscriptions.', 'index' => 3],
        ]);

        $this->assertSame([], app(KeywordSearch::class)->search($document->getKey(), 'policy'));
    }

    public function test_longer_chunks_do_not_dominate_short_ones(): void
    {
        $document = $this->documentWith([
            [
                'text' => 'Razorpay is used for subscriptions. '.str_repeat('Padding words here. ', 30),
                'index' => 0,
            ],
            ['text' => 'Razorpay handles the checkout flow.', 'index' => 1],
            ['text' => 'The office closes at six on Fridays.', 'index' => 2],
        ]);

        $hits = app(KeywordSearch::class)->search($document->getKey(), 'razorpay');

        $this->assertNotEmpty($hits);
        $this->assertSame(1, $hits[0]['chunk_index']);
    }

    public function test_focus_keeps_the_matched_term_inside_the_window(): void
    {
        $text = str_repeat('The system stores ordinary configuration values. ', 20)
            .'Payments are processed through Razorpay. '
            .str_repeat('Further notes follow here. ', 20);

        $window = app(KeywordSearch::class)->focus($text, ['razorpay'], 200);

        $this->assertStringContainsString('Razorpay', $window);
        $this->assertLessThanOrEqual(203, mb_strlen($window));
    }

    public function test_focus_returns_the_head_when_nothing_matches(): void
    {
        $text = 'Opening line. '.str_repeat('Padding. ', 80);

        $window = app(KeywordSearch::class)->focus($text, ['absent'], 100);

        $this->assertStringStartsWith('Opening line.', $window);
    }

    public function test_very_short_query_tokens_are_ignored(): void
    {
        $document = $this->documentWith([
            ['text' => 'The contract runs for a period of time.', 'index' => 0],
        ]);

        $this->assertSame([], app(KeywordSearch::class)->search($document->getKey(), 'a an to'));
    }

    /**
     * @param  list<array{text: string, index: int}>  $chunks
     */
    private function documentWith(array $chunks): Document
    {
        $user = User::factory()->create();
        $document = Document::factory()->create(['user_id' => $user->getKey()]);

        $embeddings = app(EmbeddingClient::class);
        $vectors = $embeddings->embed(array_column($chunks, 'text'));

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
