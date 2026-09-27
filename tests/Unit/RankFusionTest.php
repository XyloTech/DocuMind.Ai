<?php

namespace Tests\Unit;

use App\Services\RAG\RankFusion;
use Tests\TestCase;

class RankFusionTest extends TestCase
{
    public function test_a_chunk_ranked_by_both_lists_wins(): void
    {
        $fusion = new RankFusion;

        $fused = $fusion->fuse(
            [['chunk_index' => 1], ['chunk_index' => 2]],
            [['chunk_index' => 2], ['chunk_index' => 1]],
        );

        // Chunks 1 and 2 swap places, so they tie and order by index.
        $this->assertSame([1, 2], array_column($fused, 'chunk_index'));
        $this->assertEqualsWithDelta(
            $fused[0]['score'],
            $fused[1]['score'],
            0.000001,
        );
    }

    public function test_agreement_beats_a_higher_single_list_rank(): void
    {
        $fusion = new RankFusion;

        $fused = $fusion->fuse(
            [['chunk_index' => 9], ['chunk_index' => 4], ['chunk_index' => 7]],
            [['chunk_index' => 4], ['chunk_index' => 9]],
        );

        $order = array_column($fused, 'chunk_index');

        // 9 is first for vectors and second for keywords; 7 is second for
        // vectors only, so it cannot outrank the pair.
        $this->assertContains(4, array_slice($order, 0, 2));
        $this->assertContains(9, array_slice($order, 0, 2));
        $this->assertSame(7, $order[count($order) - 1]);
    }

    public function test_a_chunk_found_by_only_one_list_still_survives(): void
    {
        $fusion = new RankFusion;

        $fused = $fusion->fuse(
            [['chunk_index' => 5]],
            [['chunk_index' => 8]],
        );

        $this->assertEqualsCanonicalizing([5, 8], array_column($fused, 'chunk_index'));
    }

    public function test_duplicate_entries_in_one_list_are_summed(): void
    {
        $fusion = new RankFusion;

        $fused = $fusion->fuse(
            [['chunk_index' => 3], ['chunk_index' => 3]],
        );

        $this->assertCount(1, $fused);
        $this->assertSame(3, $fused[0]['chunk_index']);
    }

    public function test_composite_keys_survive_fusion(): void
    {
        $fusion = new RankFusion;

        $fused = $fusion->fuse(
            [['chunk_index' => '5:0'], ['chunk_index' => '5:1']],
            [['chunk_index' => '9:3'], ['chunk_index' => '5:0']],
        );

        $keys = array_column($fused, 'chunk_index');

        // "5:0" is ranked by both lists, so it must come first and must not be
        // collapsed into a bare integer.
        $this->assertSame('5:0', $keys[0]);
        $this->assertContains('9:3', $keys);
        $this->assertContains('5:1', $keys);
    }

    public function test_fusing_nothing_returns_nothing(): void
    {
        $fusion = new RankFusion;

        $this->assertSame([], $fusion->fuse([], []));
    }
}
