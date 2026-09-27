<?php

namespace App\Services\RAG;

/**
 * Builds the starter questions shown under an empty chat.
 *
 * The questions must be answerable *from this document*, so they are mined from
 * the text itself: section headings become "what does X cover?" and the most
 * distinctive terms become "what does the document say about X?". No model call
 * is needed, which keeps ingestion instant.
 */
class SuggestedQuestions
{
    private const int MAX_QUESTIONS = 4;

    /**
     * @param  list<string>  $texts  the document's chunks
     * @return list<string>
     */
    public function forChunks(array $texts, string $title = ''): array
    {
        $headings = $this->headings($texts);
        $topics = $this->topics($texts, $headings);

        $questions = [];

        foreach (array_slice($headings, 0, 2) as $heading) {
            $questions[] = 'What does the section on '.$this->questionCase($heading).' say?';
        }

        foreach (array_slice($topics, 0, self::MAX_QUESTIONS) as $topic) {
            $questions[] = 'What does this document say about '.$this->questionCase($topic).'?';
        }

        if ($questions === [] && $title !== '') {
            $questions[] = 'What is this document about?';
        }

        return array_slice(array_values(array_unique($questions)), 0, self::MAX_QUESTIONS);
    }

    /**
     * Drop the section number and keep the readable title.
     */
    private function questionCase(string $heading): string
    {
        $title = trim(preg_replace('/^\d+(?:\.\d+)*[.)]?\s*/u', '', $heading) ?? $heading);

        return $title === '' ? $heading : $title;
    }

    /**
     * Numbered section headings, e.g. "4.2 Session Management".
     *
     * @param  list<string>  $texts
     * @return list<string>
     */
    private function headings(array $texts): array
    {
        $frequency = $this->wordFrequency($texts);
        $headings = [];

        foreach ($texts as $text) {
            // Extracted prose keeps headings inline, so match on the numbering
            // rather than on line breaks: "4.2 Session Management Schools can…".
            preg_match_all(
                '~(?<![0-9.])(\d+(?:\.\d+)*)[.)]?\s+([A-Z][^0-9\n]{3,80})~u',
                $text,
                $matches,
                PREG_SET_ORDER,
            );

            foreach ($matches as $match) {
                $title = $this->cleanHeading($match[2], $frequency);

                if ($title === '' || $this->isNoiseHeading($title)) {
                    continue;
                }

                $headings[] = $match[1].' '.$title;
            }
        }

        return array_values(array_unique($headings));
    }

    /**
     * Page furniture and version lines that merely start with a number.
     */
    private function isNoiseHeading(string $title): bool
    {
        return preg_match('/^(page|version|section|www|http)\b/i', $title) === 1
            || preg_match('/^\d+$/', $title) === 1;
    }

    /**
     * Word frequency across the document, used to find where a heading stops
     * and body prose starts.
     *
     * @param  list<string>  $texts
     * @return array<string, int>
     */
    private function wordFrequency(array $texts): array
    {
        $frequency = [];

        foreach ($texts as $text) {
            foreach ($this->words($text) as $word) {
                $frequency[$word] = ($frequency[$word] ?? 0) + 1;
            }
        }

        return $frequency;
    }

    /**
     * Keep the title words and drop the first word that reads like body prose.
     *
     * Headings are short and made of rare words; the sentence that follows
     * reuses the document's ordinary vocabulary, so the first repeated word is
     * a good boundary.
     *
     * @param  array<string, int>  $frequency
     */
    private function cleanHeading(string $run, array $frequency): string
    {
        $words = preg_split('/\s+/u', trim($run)) ?: [];
        $title = [];

        foreach ($words as $position => $word) {
            $clean = trim($word, " \t.,;:()[]\"'");

            if ($clean === '') {
                break;
            }

            $lower = mb_strtolower($clean);

            if ($position >= 2 && ($frequency[$lower] ?? 0) >= 3) {
                break;
            }

            if ($position >= 5) {
                break;
            }

            $title[] = $clean;
        }

        while ($title !== [] && in_array(mb_strtolower((string) end($title)), self::connectors(), true)) {
            array_pop($title);
        }

        while ($title !== [] && in_array(mb_strtolower((string) $title[0]), self::connectors(), true)) {
            array_shift($title);
        }

        $heading = trim(implode(' ', $title));
        $heading = preg_replace('/\s+(Page|Version|Section)\b.*$/iu', '', $heading) ?? $heading;

        return trim(rtrim(trim($heading), ' .,;:-'));
    }

    /**
     * @return list<string>
     */
    private static function connectors(): array
    {
        return ['the', 'a', 'an', 'and', 'or', 'of', 'for', 'to', 'in', 'on', 'with', 'that', 'which'];
    }

    /**
     * Subject matter worth asking about, preferring two-word phrases.
     *
     * "What does this document say about proxy engine?" is a far better
     * prompt than the same question about a single frequent word, so adjacent
     * content words are paired up first.
     *
     * @param  list<string>  $texts
     * @param  list<string>  $headings
     * @return list<string>
     */
    private function topics(array $texts, array $headings = []): array
    {
        $stop = $this->stopWords();
        $headingWords = $this->words(implode(' ', $headings));
        $singles = [];
        $pairs = [];
        $triples = [];

        foreach ($texts as $text) {
            $words = array_values(array_filter(
                $this->words($text),
                fn (string $word): bool => $this->usable($word, $stop, $headingWords),
            ));

            foreach ($words as $word) {
                $singles[$word] = ($singles[$word] ?? 0) + 1;
            }

            for ($index = 0; $index < count($words) - 1; $index++) {
                $pair = $words[$index].' '.$words[$index + 1];
                $pairs[$pair] = ($pairs[$pair] ?? 0) + 1;

                if ($index < count($words) - 2) {
                    $triple = $pair.' '.$words[$index + 2];
                    $triples[$triple] = ($triples[$triple] ?? 0) + 1;
                }
            }
        }

        arsort($triples);
        arsort($pairs);
        arsort($singles);

        $topics = [];
        $used = [];

        // Longest phrase first: "role based access" beats "role based", and
        // consuming all three words stops a lonely "access" appearing too.
        foreach ([$triples, $pairs, $singles] as $candidates) {
            foreach ($candidates as $phrase => $hits) {
                if (count($topics) >= 3) {
                    break 2;
                }

                if ($hits < 2) {
                    continue;
                }

                $words = explode(' ', (string) $phrase);
                $overlaps = array_intersect($words, $used);

                if ($overlaps !== []) {
                    continue;
                }

                $topics[] = (string) $phrase;
                $used = array_merge($used, $words);
            }
        }

        foreach (array_keys($singles) as $word) {
            if (count($topics) >= 4) {
                break;
            }

            if (! in_array($word, $used, true) && ($singles[$word] ?? 0) >= 3) {
                $topics[] = (string) $word;
                $used[] = $word;
            }
        }

        return array_slice($topics, 0, self::MAX_QUESTIONS * 2);
    }

    /**
     * @param  list<string>  $stop
     * @param  list<string>  $headingWords
     */
    private function usable(string $word, array $stop, array $headingWords): bool
    {
        return mb_strlen($word) >= 4
            && ! in_array($word, $stop, true)
            && ! in_array($word, $headingWords, true);
    }

    /**
     * @return list<string>
     */
    private function words(string $text): array
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        // Years, section numbers and other digits are never useful topic words.
        return array_values(array_filter(
            $words,
            fn (string $word): bool => preg_match('/\d/', $word) !== 1,
        ));
    }

    /**
     * @return list<string>
     */
    private function stopWords(): array
    {
        return [
            'about', 'above', 'after', 'again', 'against', 'along', 'already', 'also', 'although',
            'always', 'among', 'another', 'around', 'because', 'been', 'before', 'being', 'below',
            'between', 'both', 'could', 'does', 'doing', 'done', 'down', 'during', 'each', 'either',
            'else', 'enough', 'every', 'from', 'further', 'have', 'having', 'here', 'however', 'into',
            'itself', 'just', 'like', 'make', 'many', 'more', 'most', 'much', 'must', 'only', 'other',
            'over', 'same', 'shall', 'should', 'since', 'some', 'such', 'than', 'that', 'their',
            'theirs', 'them', 'then', 'there', 'these', 'they', 'this', 'those', 'through', 'under',
            'until', 'very', 'were', 'what', 'when', 'where', 'which', 'while', 'will', 'with',
            'would', 'your', 'yours',
            // page furniture and dates are never worth a question
            'version', 'january', 'february', 'march', 'april', 'may', 'june', 'july', 'august',
            'september', 'october', 'november', 'december', 'monday', 'tuesday', 'wednesday',
            'thursday', 'friday', 'saturday', 'sunday',             'copyright', 'draft', 'white', 'page', 'pages', 'table', 'figure', 'section',
        ];
    }
}
