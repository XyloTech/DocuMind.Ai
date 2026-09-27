<?php

namespace Tests\Unit;

use App\Services\Pdf\PageNormalizer;
use App\Services\RAG\Chunker;
use PHPUnit\Framework\TestCase;

class ChunkerTest extends TestCase
{
    public function test_chunks_never_exceed_the_hard_cap(): void
    {
        $chunks = (new Chunker)->chunk([1 => $this->document()]);

        $this->assertGreaterThan(1, count($chunks));

        foreach ($chunks as $chunk) {
            $this->assertLessThanOrEqual(620, mb_strlen($chunk['text']));
            $this->assertGreaterThanOrEqual(40, mb_strlen($chunk['text']));
        }
    }

    public function test_chunk_indexes_are_sequential_and_pages_are_tracked(): void
    {
        $chunks = (new Chunker)->chunk([
            1 => $this->paragraph(10),
            2 => $this->paragraph(10, 'Second page sentence'),
        ]);

        $this->assertSame(range(0, count($chunks) - 1), array_column($chunks, 'chunk_index'));
        $this->assertContains(1, array_column($chunks, 'page_to'));
        $this->assertContains(2, array_column($chunks, 'page_to'));
        $this->assertSame(1, min(array_column($chunks, 'page_from')));
    }

    public function test_consecutive_chunks_share_their_boundary_sentence(): void
    {
        $text = implode(' ', array_map(fn (int $i): string => $this->sentence($i), range(0, 39)));

        $chunks = (new Chunker)->chunk([1 => $text]);

        $this->assertGreaterThan(1, count($chunks));

        foreach ($chunks as $index => $chunk) {
            if ($index === 0) {
                continue;
            }

            $this->assertStringStartsWith(
                $this->tailSentence($chunks[$index - 1]['text']),
                $chunk['text'],
                "Chunk {$index} should start with the previous chunk's tail sentence.",
            );
        }
    }

    public function test_abbreviations_and_decimals_do_not_break_sentences(): void
    {
        $text = 'Mr. Smith paid 3.14 dollars to Dr. Jones today. '
            .'Figure 2 shows e.g. a sample result. '
            .'See Vol. 3 for the full list of items.';

        $chunks = (new Chunker)->chunk([1 => $text]);

        $this->assertCount(1, $chunks);
        $this->assertSame($text, $chunks[0]['text']);
    }

    public function test_a_single_over_long_sentence_is_split_on_word_boundaries(): void
    {
        $words = array_map(fn (int $i): string => 'word'.$i, range(1, 300));

        $chunks = (new Chunker)->chunk([1 => implode(' ', $words)]);

        $this->assertGreaterThan(1, count($chunks));

        $occurrences = 0;

        foreach ($chunks as $chunk) {
            $this->assertLessThanOrEqual(620, mb_strlen($chunk['text']));
            $this->assertMatchesRegularExpression('/^word\d+$/', explode(' ', $chunk['text'])[0]);
            $occurrences += substr_count($chunk['text'], 'word');
        }

        $this->assertSame(300, $occurrences);
    }

    public function test_identical_pages_do_not_produce_duplicate_chunks(): void
    {
        $sentence = rtrim(str_repeat('alpha beta gamma delta epsilon zeta eta theta ', 15));

        $chunks = (new Chunker)->chunk([1 => $sentence, 2 => $sentence]);

        $this->assertCount(2, $chunks);
        $this->assertSame(
            array_unique(array_column($chunks, 'content_hash')),
            array_column($chunks, 'content_hash'),
        );
    }

    public function test_a_document_of_tiny_fragments_still_yields_a_chunk(): void
    {
        $chunks = (new Chunker)->chunk([7 => 'Page 7']);

        $this->assertCount(1, $chunks);
        $this->assertSame('Page 7', $chunks[0]['text']);
        $this->assertSame(7, $chunks[0]['page_from']);
    }

    public function test_soft_wrapped_lines_are_reassembled_into_sentences(): void
    {
        $normalized = (new PageNormalizer)->normalize([1 => "The board approved the\nbudget after a lengthy\n debate. Motion carried."])[1];

        $chunks = (new Chunker)->chunk([1 => $normalized]);

        $this->assertCount(1, $chunks);
        $this->assertStringContainsString('The board approved the budget after a lengthy debate.', $chunks[0]['text']);
        $this->assertStringContainsString('Motion carried.', $chunks[0]['text']);
    }

    private function sentence(int $i): string
    {
        return "Sentence number {$i} is here with enough words to matter.";
    }

    private function tailSentence(string $chunk): string
    {
        $parts = explode('. ', $chunk);

        return end($parts);
    }

    private function paragraph(int $count, string $prefix = 'First page sentence'): string
    {
        return implode(' ', array_map(
            fn (int $i): string => "{$prefix} number {$i} carries a reasonable amount of body copy for chunking.",
            range(1, $count),
        ));
    }

    private function document(): string
    {
        return implode(' ', array_map(fn (int $i): string => $this->sentence($i), range(0, 59)));
    }
}
