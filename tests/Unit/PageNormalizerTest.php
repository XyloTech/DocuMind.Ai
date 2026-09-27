<?php

namespace Tests\Unit;

use App\Services\Pdf\PageNormalizer;
use PHPUnit\Framework\TestCase;

class PageNormalizerTest extends TestCase
{
    public function test_soft_wrapped_lines_become_one_line(): void
    {
        $pages = (new PageNormalizer)->normalize([1 => "The quick brown\nfox jumps over\nthe lazy dog."]);

        $this->assertSame('The quick brown fox jumps over the lazy dog.', $pages[1]);
    }

    public function test_blank_lines_survive_as_paragraph_breaks(): void
    {
        $pages = (new PageNormalizer)->normalize([1 => "First paragraph here.\n\nSecond paragraph here."]);

        $this->assertSame("First paragraph here.\n\nSecond paragraph here.", $pages[1]);
    }

    public function test_hyphenated_line_breaks_are_rejoined(): void
    {
        $pages = (new PageNormalizer)->normalize([1 => "A very long exam-\nple of extracted text."]);

        $this->assertSame('A very long example of extracted text.', $pages[1]);
    }

    public function test_smart_quotes_and_ligatures_are_flattened(): void
    {
        $pages = (new PageNormalizer)->normalize([1 => "\u{201C}Hello\u{201D} said the \u{FB01}le, a\u{2014}b."]);

        $this->assertSame('"Hello" said the file, a-b.', $pages[1]);
    }

    public function test_all_caps_headings_become_their_own_paragraph(): void
    {
        $pages = (new PageNormalizer)->normalize([1 => "Body copy continues here\nREFUND POLICY\nMore body copy follows."]);

        $this->assertSame("Body copy continues here\n\nREFUND POLICY\n\nMore body copy follows.", $pages[1]);
    }

    public function test_running_headers_are_removed_from_every_page(): void
    {
        $raw = [];

        foreach (range(1, 5) as $page) {
            $raw[$page] = "ACME CORP CONFIDENTIAL\n\nBody copy for page {$page}.";
        }

        $pages = (new PageNormalizer)->normalize($raw);

        foreach ($pages as $text) {
            $this->assertStringNotContainsString('ACME CORP CONFIDENTIAL', $text);
            $this->assertStringStartsWith('Body copy for page', $text);
        }
    }

    public function test_page_numbers_are_preserved_as_keys(): void
    {
        $pages = (new PageNormalizer)->normalize([1 => 'One.', 2 => 'Two.', 3 => 'Three.']);

        $this->assertSame([1, 2, 3], array_keys($pages));
    }
}
