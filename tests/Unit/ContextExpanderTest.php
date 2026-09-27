<?php

namespace Tests\Unit;

use App\Services\RAG\ContextExpander;
use Tests\TestCase;

class ContextExpanderTest extends TestCase
{
    private ContextExpander $expander;

    protected function setUp(): void
    {
        parent::setUp();

        $this->expander = new ContextExpander;
    }

    public function test_the_previous_chunk_is_prepended_for_context(): void
    {
        $chunks = [
            ['chunk_index' => 0, 'text' => 'Firestore rules govern access.'],
            ['chunk_index' => 1, 'text' => 'They are reviewed every quarter.'],
        ];

        $expanded = $this->expander->expand($chunks[1], $chunks);

        $this->assertStringContainsString('Firestore rules govern access.', $expanded);
        $this->assertStringContainsString('They are reviewed every quarter.', $expanded);
        $this->assertStringStartsWith('Firestore rules govern access.', $expanded);
    }

    public function test_the_first_chunk_has_no_neighbour_to_prepend(): void
    {
        $chunks = [['chunk_index' => 0, 'text' => 'Opening sentence.']];

        $this->assertSame('Opening sentence.', $this->expander->expand($chunks[0], $chunks));
    }

    public function test_a_long_neighbour_is_trimmed_to_whole_sentences(): void
    {
        $previous = str_repeat('Filler sentence. ', 40).'The subject is payment credentials.';
        $chunks = [
            ['chunk_index' => 0, 'text' => $previous],
            ['chunk_index' => 1, 'text' => 'They live in environment variables.'],
        ];

        $expanded = $this->expander->expand($chunks[1], $chunks, 120);

        $this->assertStringContainsString('The subject is payment credentials.', $expanded);
        $this->assertStringContainsString('They live in environment variables.', $expanded);
        // The context window is respected (plus a little for the hit itself).
        $this->assertLessThanOrEqual(120 + mb_strlen($chunks[1]['text']) + 1, mb_strlen($expanded));
    }

    public function test_a_neighbour_without_sentence_breaks_keeps_its_tail(): void
    {
        $chunks = [
            ['chunk_index' => 0, 'text' => 'a partial clause that runs on and on and never ends with a full stop'],
            ['chunk_index' => 1, 'text' => 'Continued.'],
        ];

        $expanded = $this->expander->expand($chunks[1], $chunks, 30);

        $this->assertStringEndsWith('Continued.', $expanded);
    }
}
