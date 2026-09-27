<?php

namespace App\Jobs;

use App\Enums\DocumentStatus;
use App\Enums\NotificationType;
use App\Exceptions\EmbeddingException;
use App\Exceptions\PdfExtractionException;
use App\Models\Document;
use App\Services\Notifier;
use App\Services\Pdf\PdfTextExtractor;
use App\Services\RAG\Chunker;
use App\Services\RAG\EmbeddingClient;
use App\Services\RAG\SuggestedQuestions;
use App\Services\RAG\VectorStore;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Extract → normalize → chunk → embed → persist.
 *
 * Every stage writes progress back to the document so the dashboard bar moves
 * live. Extraction problems are permanent (a scanned PDF stays scanned) and
 * fail immediately; embedding problems are treated as transient and retried.
 */
class ProcessDocumentJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public int $tries = 3;

    /**
     * @var list<int>
     */
    public array $backoff = [5, 30, 120];

    public int $uniqueFor = 3600;

    /**
     * Quarter of the pipeline last written to the lifecycle notification.
     * The embedding callback reports progress per batch, so without this the
     * notifications table would see a write for every batch of a large PDF.
     */
    private int $lastNotifiedProgressBucket = -1;

    public function __construct(public readonly Document $document) {}

    public function uniqueId(): string
    {
        return 'process-document:'.$this->document->getKey();
    }

    public function handle(
        PdfTextExtractor $extractor,
        EmbeddingClient $embeddings,
        VectorStore $vectors,
    ): void {
        $document = Document::query()->find($this->document->getKey());

        if ($document === null || $document->status === DocumentStatus::Processed) {
            return;
        }

        $this->progress($document, DocumentStatus::Processing, 2);

        try {
            $extracted = $this->extract($document, $extractor);
            $chunks = $this->chunk($document, $extracted);
            $rows = $this->embed($document, $chunks, $embeddings, $vectors);
        } catch (PdfExtractionException $exception) {
            $this->markFailed($document, $exception->getMessage());

            return;
        }

        $vectors->persist($document, $rows);

        $document->forceFill([
            'status' => DocumentStatus::Processed,
            'progress' => 100,
            'page_count' => $extracted['page_count'],
            'chunk_count' => count($rows),
            'error_message' => null,
            'processed_at' => now(),
            'suggested_questions' => $this->suggestions($document, $rows),
        ])->save();

        $vectors->forget($document);

        Notifier::make()
            ->type(NotificationType::KnowledgeProcessed)
            ->to($document->user)
            ->title('Document ready')
            ->body('"'.$document->filename.'" is indexed and ready to answer questions.')
            ->link(route('dashboard'), 'View document')
            ->workspace($document->workspace_id)
            ->dedupe('knowledge.uploaded.'.$document->getKey())
            ->send();
    }

    public function failed(?Throwable $exception): void
    {
        $document = Document::query()->find($this->document->getKey());

        if ($document === null) {
            return;
        }

        $this->markFailed($document, $exception?->getMessage() ?? 'Processing failed.');
    }

    /**
     * @return array{pages: array<int, string>, page_count: int}
     *
     * @throws PdfExtractionException
     */
    private function extract(Document $document, PdfTextExtractor $extractor): array
    {
        $path = Storage::disk((string) config('documents.disk'))->path($document->file_path);

        $extracted = $extractor->extract($path);

        $this->progress($document, DocumentStatus::Processing, 30, [
            'page_count' => $extracted['page_count'],
        ]);

        return $extracted;
    }

    /**
     * @param  array{pages: array<int, string>, page_count: int}  $extracted
     * @return list<array{chunk_index: int, text: string, page_from: int, page_to: int, char_start: int, char_end: int, token_estimate: int, content_hash: string}>
     *
     * @throws PdfExtractionException
     */
    private function chunk(Document $document, array $extracted): array
    {
        $chunks = Chunker::fromConfig()->chunk($extracted['pages']);

        if ($chunks === []) {
            throw PdfExtractionException::noSelectableText();
        }

        $this->progress($document, DocumentStatus::Processing, 55);

        return $chunks;
    }

    /**
     * @param  list<array{chunk_index: int, text: string, page_from: int, page_to: int, char_start: int, char_end: int, token_estimate: int, content_hash: string}>  $chunks
     * @return list<array{chunk_index: int, text: string, page_from: int, page_to: int, char_start: int, char_end: int, token_estimate: int, content_hash: string, vector: list<float>}>
     *
     * @throws EmbeddingException
     */
    private function embed(Document $document, array $chunks, EmbeddingClient $embeddings, VectorStore $vectors): array
    {
        $estimatedTokens = array_sum(array_column($chunks, 'token_estimate'));
        $limit = (int) config('rag.max_document_tokens');

        if ($estimatedTokens > $limit) {
            throw EmbeddingException::documentTooLarge($estimatedTokens, $limit);
        }

        $document->chunks()->delete();
        $vectors->forget($document);

        $embedded = $embeddings->embed(array_column($chunks, 'text'), function (int $done, int $total) use ($document): void {
            $progress = 55 + (int) floor(($done / max(1, $total)) * 40);

            $this->progress($document, DocumentStatus::Processing, min(95, $progress));
        });

        $rows = [];

        foreach ($chunks as $index => $chunk) {
            $rows[] = [...$chunk, 'vector' => $embedded[$index]];
        }

        return $rows;
    }

    /**
     * Starter questions mined from the document itself, so an empty chat can
     * offer something specific rather than generic prompts.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<string>
     */
    private function suggestions(Document $document, array $rows): array
    {
        return app(SuggestedQuestions::class)->forChunks(
            array_column($rows, 'text'),
            $document->filename,
        );
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function progress(Document $document, DocumentStatus $status, int $progress, array $extra = []): void
    {
        $document->forceFill([
            'status' => $status,
            'progress' => $progress,
            ...$extra,
        ])->save();

        $bucket = (int) floor($progress / 25);

        if ($bucket === $this->lastNotifiedProgressBucket) {
            return;
        }

        $this->lastNotifiedProgressBucket = $bucket;

        Notifier::make()
            ->type(NotificationType::KnowledgeUploaded)
            ->to($document->user)
            ->title('Indexing "'.$document->filename.'"')
            ->body($progress.'% — '.self::stageLabel($progress))
            ->link(route('dashboard'), 'View document')
            ->workspace($document->workspace_id)
            ->dedupe('knowledge.uploaded.'.$document->getKey())
            ->send();
    }

    private static function stageLabel(int $progress): string
    {
        return match (true) {
            $progress < 30 => 'extracting text',
            $progress < 55 => 'splitting into passages',
            default => 'creating embeddings',
        };
    }

    private function markFailed(Document $document, string $message): void
    {
        $document->forceFill([
            'status' => DocumentStatus::Failed,
            'progress' => 100,
            'error_message' => mb_substr(trim($message) ?: 'Processing failed.', 0, 500),
            'processed_at' => now(),
        ])->save();

        // Close the lifecycle entry first so it never keeps claiming the
        // document is part-way through, then raise the alert row that carries
        // the reason (a fresh row, because an error must re-toast).
        Notifier::make()
            ->type(NotificationType::KnowledgeUploaded)
            ->to($document->user)
            ->title('Indexing stopped')
            ->body('"'.$document->filename.'" did not finish indexing.')
            ->link(route('dashboard'), 'View document')
            ->workspace($document->workspace_id)
            ->dedupe('knowledge.uploaded.'.$document->getKey())
            ->send();

        Notifier::make()
            ->type(NotificationType::KnowledgeFailed)
            ->to($document->user)
            ->title('Document processing failed')
            ->body('"'.$document->filename.'" could not be processed: '.mb_substr(trim($message) ?: 'Processing failed.', 0, 200))
            ->link(route('dashboard'), 'Open document')
            ->workspace($document->workspace_id)
            ->dedupe('knowledge.failed.'.$document->getKey())
            ->send();
    }
}
