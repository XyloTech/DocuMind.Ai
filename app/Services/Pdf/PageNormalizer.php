<?php

namespace App\Services\Pdf;

/**
 * Turns raw PDF page text into clean, paragraph-aware pages ready for chunking.
 *
 * @phpstan-type Pages array<int, string>
 */
class PageNormalizer
{
    private const string PARAGRAPH_BREAK = "\n\n";

    /**
     * @param  Pages  $pages  1-based page number => raw extracted text
     * @return Pages 1-based page number => normalized text
     */
    public function normalize(array $pages): array
    {
        $pages = $this->stripRunningHeaders($pages);

        $normalized = [];

        foreach ($pages as $number => $text) {
            $normalized[$number] = $this->normalizePage($text);
        }

        return $normalized;
    }

    private function normalizePage(string $text): string
    {
        $text = $this->replaceUnicode($text);
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = $this->dehyphenate($text);
        $text = $this->joinLines($text);
        $text = $this->collapseWhitespace($text);

        return trim($text);
    }

    /**
     * `exam-\nple` becomes `example`; soft hyphens disappear entirely.
     */
    private function dehyphenate(string $text): string
    {
        $text = preg_replace('/(\p{L})-\R+[ \t]*(\p{L})/u', '$1$2', $text) ?? $text;

        return preg_replace('/\x{00AD}/u', '', $text) ?? $text;
    }

    /**
     * Single newlines are soft wraps and become spaces; blank lines, headings
     * and bullet items survive as hard paragraph breaks.
     */
    private function joinLines(string $text): string
    {
        $result = '';
        $buffer = '';

        foreach (explode("\n", $text) as $line) {
            $trimmed = trim($line);

            if ($trimmed === '') {
                $result = $this->flush($result, $buffer).self::PARAGRAPH_BREAK;
                $buffer = '';

                continue;
            }

            if ($this->isHeading($trimmed) || $this->isBullet($trimmed)) {
                $result = $this->flush($result, $buffer).$trimmed.self::PARAGRAPH_BREAK;
                $buffer = '';

                continue;
            }

            $buffer = $buffer === '' ? $trimmed : $buffer.' '.$trimmed;
        }

        $result = $this->flush($result, $buffer);

        return $result;
    }

    private function flush(string $result, string $buffer): string
    {
        return $buffer === '' ? $result : $result.$buffer.self::PARAGRAPH_BREAK;
    }

    private function isHeading(string $line): bool
    {
        if (mb_strlen($line) > 90 || preg_match('/[.!?…]$/u', $line) === 1) {
            return false;
        }

        if (preg_match('/\p{L}/u', $line) === 1 && mb_strtoupper($line, 'UTF-8') === $line) {
            return true;
        }

        return preg_match('/^(?:\d+(?:\.\d+){0,3})[ \t]+\p{Lu}/u', $line) === 1
            && mb_strlen($line) <= 80;
    }

    private function isBullet(string $line): bool
    {
        return preg_match('/^[-*•◦▪‣·][ \t]+/u', $line) === 1;
    }

    private function collapseWhitespace(string $text): string
    {
        $text = preg_replace('/[^\S\n]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/ *\n */', "\n", $text) ?? $text;

        return preg_replace('/\n{3,}/', self::PARAGRAPH_BREAK, $text) ?? $text;
    }

    private function replaceUnicode(string $text): string
    {
        return strtr($text, [
            "\u{2018}" => "'", "\u{2019}" => "'",
            "\u{201A}" => "'", "\u{201B}" => "'",
            "\u{201C}" => '"', "\u{201D}" => '"', "\u{201E}" => '"',
            "\u{2013}" => '-', "\u{2014}" => '-',
            "\u{2026}" => '...',
            "\u{00A0}" => ' ',
            "\u{FB01}" => 'fi', "\u{FB02}" => 'fl',
            "\u{FB00}" => 'ff', "\u{FB03}" => 'ffi', "\u{FB04}" => 'ffl',
        ]);
    }

    /**
     * Drop running headers/footers: any edge line repeated on 60%+ of pages.
     *
     * @param  Pages  $pages
     * @return Pages
     */
    private function stripRunningHeaders(array $pages): array
    {
        if (count($pages) < 3) {
            return $pages;
        }

        $threshold = (int) ceil(count($pages) * 0.6);
        $counts = [];

        foreach ($pages as $text) {
            foreach (array_unique($this->edgeLines($text)) as $line) {
                $counts[$line] = ($counts[$line] ?? 0) + 1;
            }
        }

        $repeated = array_filter($counts, fn (int $count): bool => $count >= $threshold);

        if ($repeated === []) {
            return $pages;
        }

        foreach ($pages as $number => $text) {
            $pages[$number] = $this->removeLines($text, array_keys($repeated));
        }

        return $pages;
    }

    /**
     * @return list<string>
     */
    private function edgeLines(string $text): array
    {
        $lines = array_values(array_filter(array_map('trim', explode("\n", $text)), fn (string $line): bool => $line !== ''));

        $edge = array_merge(array_slice($lines, 0, 3), array_slice($lines, -3));

        return array_values(array_filter(array_map(fn (string $line): string => $this->squash($line), $edge), function (string $line): bool {
            return $line !== '' && mb_strlen($line) <= 120;
        }));
    }

    /**
     * @param  list<string>  $needles
     */
    private function removeLines(string $text, array $needles): string
    {
        $keep = [];

        foreach (explode("\n", $text) as $line) {
            if (in_array($this->squash($line), $needles, true)) {
                continue;
            }

            $keep[] = $line;
        }

        return implode("\n", $keep);
    }

    private function squash(string $line): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $line));
    }
}
