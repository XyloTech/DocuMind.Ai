<?php

namespace App\Services\RAG;

/**
 * Sentence-aware splitter.
 *
 * Targets ~500 character chunks with ~50 characters of trailing overlap,
 * never splitting mid-word, and carrying page provenance so the UI can show a
 * source badge. All tunables come from config/rag.php.
 */
class Chunker
{
    /**
     * @var array<string, true>
     */
    private array $seen = [];

    public function __construct(
        private readonly int $size = 500,
        private readonly int $overlap = 50,
        private readonly int $max = 620,
        private readonly int $min = 40,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            (int) config('rag.chunk_size', 500),
            (int) config('rag.chunk_overlap', 50),
            (int) config('rag.chunk_max', 620),
            (int) config('rag.chunk_min', 40),
        );
    }

    /**
     * @param  array<int, string>  $segments  page number => normalized page text
     * @return list<array{chunk_index: int, text: string, page_from: int, page_to: int, char_start: int, char_end: int, token_estimate: int, content_hash: string}>
     */
    public function chunk(array $segments): array
    {
        $this->seen = [];

        $sentences = $this->flatten($segments);
        $chunks = [];

        $count = count($sentences);
        $i = 0;

        while ($i < $count) {
            $start = $i;
            $length = 0;
            $j = $i;

            while ($j < $count) {
                $candidate = mb_strlen($sentences[$j]['text']);

                if ($j > $start && $length + $candidate > $this->size) {
                    break;
                }

                $length += ($j > $start ? 1 : 0) + $candidate;
                $j++;

                if ($length >= $this->size) {
                    break;
                }
            }

            if ($j === $start) {
                $j = $start + 1;
            }

            if ($j - $start === 1 && $length > $this->max) {
                $this->emitLongSentence($chunks, $sentences[$start]);
                $i = $start + 1;

                continue;
            }

            $slice = array_slice($sentences, $start, $j - $start);
            $text = implode(' ', array_column($slice, 'text'));

            if ($this->accept($text)) {
                $chunks[] = [
                    'chunk_index' => count($chunks),
                    'text' => $text,
                    'page_from' => (int) min(array_column($slice, 'page')),
                    'page_to' => (int) max(array_column($slice, 'page')),
                    'char_start' => (int) $slice[0]['start'],
                    'char_end' => (int) $slice[array_key_last($slice)]['end'],
                    'token_estimate' => (int) ceil(mb_strlen($text) / 4),
                    'content_hash' => sha1($text),
                ];
            }

            if ($j >= $count) {
                break;
            }

            $i = $this->nextStart($sentences, $start, $j);
        }

        if ($chunks === [] && $sentences !== []) {
            $this->emitFallback($chunks, $sentences);
        }

        return $chunks;
    }

    /**
     * A document made only of tiny fragments (page numbers, a lone heading)
     * still deserves to be searchable.
     *
     * @param  list<array{chunk_index: int, text: string, page_from: int, page_to: int, char_start: int, char_end: int, token_estimate: int, content_hash: string}>  $chunks
     * @param  list<array{text: string, page: int, start: int, end: int}>  $sentences
     */
    private function emitFallback(array &$chunks, array $sentences): void
    {
        $text = trim(implode(' ', array_column($sentences, 'text')));

        if ($text === '') {
            return;
        }

        $chunks[] = [
            'chunk_index' => 0,
            'text' => $text,
            'page_from' => (int) min(array_column($sentences, 'page')),
            'page_to' => (int) max(array_column($sentences, 'page')),
            'char_start' => (int) $sentences[0]['start'],
            'char_end' => (int) $sentences[array_key_last($sentences)]['end'],
            'token_estimate' => (int) ceil(mb_strlen($text) / 4),
            'content_hash' => sha1($text),
        ];
    }

    /**
     * @param  array<int, string>  $segments
     * @return list<array{text: string, page: int, start: int, end: int}>
     */
    private function flatten(array $segments): array
    {
        $sentences = [];
        $cursor = 0;

        foreach ($segments as $page => $text) {
            foreach ($this->splitSentences((string) $text) as $sentence) {
                $length = mb_strlen($sentence);

                $sentences[] = [
                    'text' => $sentence,
                    'page' => (int) $page,
                    'start' => $cursor,
                    'end' => $cursor + $length,
                ];

                $cursor += $length + 1;
            }
        }

        return $sentences;
    }

    /**
     * Carries trailing sentences (target `overlap` chars) into the next chunk.
     *
     * @param  list<array{text: string, page: int, start: int, end: int}>  $sentences
     */
    private function nextStart(array $sentences, int $start, int $end): int
    {
        $next = $end;
        $acc = 0;

        for ($k = $end - 1; $k > $start; $k--) {
            $length = mb_strlen($sentences[$k]['text']);

            if ($acc === 0 && $length > $this->overlap * 2) {
                break;
            }

            if ($acc > 0 && $acc + $length > $this->overlap * 2) {
                break;
            }

            $acc += ($acc > 0 ? 1 : 0) + $length;
            $next = $k;

            if ($acc >= $this->overlap) {
                break;
            }
        }

        return max($start + 1, $next);
    }

    /**
     * @param  list<array{text: string, page: int, start: int, end: int}>  $sentences
     * @param  list<array{chunk_index: int, text: string, page_from: int, page_to: int, char_start: int, char_end: int, token_estimate: int, content_hash: string}>  $chunks
     */
    private function emitLongSentence(array &$chunks, array $sentence): void
    {
        $offset = 0;
        $page = $sentence['page'];

        foreach ($this->splitLong($sentence['text']) as $piece) {
            $length = mb_strlen($piece);

            if (! $this->accept($piece)) {
                $offset += $length + 1;

                continue;
            }

            $chunks[] = [
                'chunk_index' => count($chunks),
                'text' => $piece,
                'page_from' => $page,
                'page_to' => $page,
                'char_start' => $sentence['start'] + $offset,
                'char_end' => $sentence['start'] + $offset + $length,
                'token_estimate' => (int) ceil($length / 4),
                'content_hash' => sha1($piece),
            ];

            $offset += $length + 1;
        }
    }

    /**
     * Rejects duplicate and degenerate fragments, but never drops a document's
     * only content.
     */
    private function accept(string $text): bool
    {
        $text = trim($text);

        if ($text === '') {
            return false;
        }

        if (mb_strlen($text) < $this->min) {
            return false;
        }

        $hash = sha1($text);

        if (isset($this->seen[$hash])) {
            return false;
        }

        $this->seen[$hash] = true;

        return true;
    }

    /**
     * @return list<string>
     */
    private function splitLong(string $text): array
    {
        $words = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $pieces = [];
        $buffer = '';

        foreach ($words as $word) {
            $candidate = $buffer === '' ? $word : $buffer.' '.$word;

            if ($buffer !== '' && mb_strlen($candidate) > $this->size) {
                $pieces[] = $buffer;
                $buffer = $word;

                continue;
            }

            $buffer = $candidate;
        }

        if ($buffer !== '') {
            $pieces[] = $buffer;
        }

        return $pieces;
    }

    /**
     * Splits on sentence-ending punctuation while protecting abbreviations,
     * initials and decimals — the usual cause of mangled chunks.
     *
     * @return list<string>
     */
    private function splitSentences(string $text): array
    {
        $text = trim($text);

        if ($text === '') {
            return [];
        }

        $marker = "\x00";
        $text = preg_replace('/(\d)\.(\d)/u', '$1'.$marker.'$2', $text) ?? $text;

        $abbreviations = [
            '/\be\.g\./iu',
            '/\bi\.e\./iu',
            '/\bet al\./iu',
            '/\b(?:Mr|Mrs|Ms|Dr|Prof|Rev|Hon|Sri|Shri|Sr|Jr|St|Mt|vs|etc|approx|dept|est|fig|vol|inc|ltd|co|cf|al)\./iu',
        ];

        foreach ($abbreviations as $pattern) {
            $text = preg_replace_callback($pattern, fn (array $match): string => str_replace('.', $marker, $match[0]), $text) ?? $text;
        }

        $pieces = [];

        foreach (preg_split('/\n{2,}/u', $text) ?: [] as $paragraph) {
            foreach (preg_split('/(?<=['.preg_quote($marker, '/').'.!?…])\s+/u', $paragraph) ?: [$paragraph] as $piece) {
                $piece = trim(str_replace($marker, '.', $piece));

                if ($piece !== '') {
                    $pieces[] = $piece;
                }
            }
        }

        return $pieces;
    }
}
