<?php

namespace Tests\Unit;

use App\Services\RAG\SuggestedQuestions;
use Tests\TestCase;

class SuggestedQuestionsTest extends TestCase
{
    private SuggestedQuestions $questions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->questions = new SuggestedQuestions;
    }

    public function test_numbered_sections_become_starter_questions(): void
    {
        $suggestions = $this->questions->forChunks([
            '1. Executive Summary Schools coordinate many interdependent activities across departments. '
                .'Version 1.0 | August 2026 Page 2 2. Administrative Challenge Reports take hours to prepare.',
        ]);

        $this->assertNotEmpty($suggestions);
        $this->assertStringContainsString('Executive Summary', $suggestions[0]);
    }

    public function test_page_furniture_is_not_offered_as_a_question(): void
    {
        $suggestions = $this->questions->forChunks([
            'Version 1.0 Page 2 Schools coordinate activities. 3. Proxy Engine Teachers need cover.',
        ]);

        foreach ($suggestions as $question) {
            $this->assertStringNotContainsString('on Page', $question);
            $this->assertStringNotContainsString('on Version', $question);
        }
    }

    public function test_phrases_are_offered_over_single_filler_words(): void
    {
        $suggestions = $this->questions->forChunks([
            'The role based access model protects records. Role based access is reviewed quarterly. '
                .'Subject compatibility matters when substituting teachers in the timetable.',
        ]);

        $joined = implode(' | ', $suggestions);

        $this->assertStringContainsString('role based', $joined);
        $this->assertStringNotContainsString('about access?', $joined);
        $this->assertStringNotContainsString('about model?', $joined);
    }

    public function test_pairs_never_overlap(): void
    {
        $suggestions = $this->questions->forChunks([
            'Teacher absence reporting tracks substitutes. Reporting covers weekly summaries. '
                .'Weekly summaries are exported as reports.',
        ]);

        $this->assertStringNotContainsString('about weekly based', implode(' ', $suggestions));
        $this->assertNotEmpty($suggestions);
    }

    public function test_an_empty_document_still_gets_one_fallback_question(): void
    {
        $suggestions = $this->questions->forChunks([], 'Policy');

        $this->assertSame(['What is this document about?'], $suggestions);
    }

    public function test_questions_are_unique_and_capped(): void
    {
        $chunk = '1. Proxy Engine Teachers need cover. The proxy engine assigns substitutes quickly.';

        $suggestions = $this->questions->forChunks([$chunk, $chunk, $chunk]);

        $this->assertLessThanOrEqual(4, count($suggestions));
        $this->assertSame($suggestions, array_values(array_unique($suggestions)));
    }
}
