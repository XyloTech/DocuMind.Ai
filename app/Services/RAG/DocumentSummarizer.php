<?php

namespace App\Services\RAG;

use App\Models\Document;
use App\Services\Ai\ChatClient;

/**
 * Whole-document summary via map-reduce.
 *
 * Feeding an entire PDF into one prompt overflows a local model's context and
 * buries the main points, so each slice is summarised on its own and the notes
 * are then condensed into a single answer. Cheap, bounded, and it never blocks
 * a visitor's chat.
 */
class DocumentSummarizer
{
    public function __construct(private readonly ChatClient $chatClient) {}

    public function summarize(Document $document): string
    {
        $chunks = app(VectorStore::class)->loadForDocument($document->getKey());

        if ($chunks === []) {
            return ChatClient::REFUSAL;
        }

        $notes = [];

        foreach ($this->slices($chunks, (int) config('rag.summary_slices', 6)) as $slice) {
            $notes[] = $this->chatClient->complete($this->summarizeSlice($slice, $document->filename))->text;
        }

        if ($notes === []) {
            return ChatClient::REFUSAL;
        }

        if (count($notes) === 1) {
            return $notes[0];
        }

        return $this->chatClient->complete($this->combine($notes, $document->filename))->text;
    }

    /**
     * @param  list<array{text: string}>  $chunks
     * @return list<string>
     */
    private function slices(array $chunks, int $slices): array
    {
        $chunksPerSlice = max(1, (int) ceil(count($chunks) / max(1, $slices)));
        $out = [];
        $buffer = [];
        $length = 0;

        foreach ($chunks as $chunk) {
            $buffer[] = $chunk['text'];
            $length += mb_strlen($chunk['text']);

            if (count($buffer) >= $chunksPerSlice) {
                $out[] = implode("\n", $buffer);
                $buffer = [];
                $length = 0;
            }
        }

        if ($buffer !== []) {
            $out[] = implode("\n", $buffer);
        }

        return $out;
    }

    /**
     * @return list<array{role: string, content: string}>
     */
    private function summarizeSlice(string $text, string $filename): array
    {
        return [
            ['role' => 'system', 'content' => 'You summarise documents for a reader who has not seen them. '
                .'Write two or three plain sentences covering only what this excerpt actually says. '
                .'No preamble, no bullet points, no meta commentary.'],
            ['role' => 'user', 'content' => "Excerpt from \"{$filename}\":\n\n"
                .mb_substr($text, 0, (int) config('rag.summary_slice_chars', 2400))
                ."\n\nSummarise this excerpt in two or three sentences."],
        ];
    }

    /**
     * @param  list<string>  $notes
     * @return list<array{role: string, content: string}>
     */
    private function combine(array $notes, string $filename): array
    {
        $joined = implode("\n\n---\n\n", $notes);

        return [
            ['role' => 'system', 'content' => 'You write executive summaries. Produce a single short paragraph '
                .'(at most six sentences) that covers the whole document. Keep concrete names, numbers and '
                .'decisions. No bullet points, no preamble.'],
            ['role' => 'user', 'content' => "Notes taken from \"{$filename}\":\n\n"
                .mb_substr($joined, 0, (int) config('rag.summary_combine_chars', 3200))
                ."\n\nWrite the single-paragraph summary."],
        ];
    }
}
