<?php

namespace App\Console\Commands;

use App\Enums\DocumentStatus;
use App\Enums\NotificationType;
use App\Jobs\ProcessDocumentJob;
use App\Models\Document;
use App\Models\User;
use App\Services\Notifier;
use App\Services\RAG\VectorStore;
use Illuminate\Console\Command;

class ReindexDocuments extends Command
{
    protected $signature = 'rag:reindex
                            {--document= : Reindex a single document by id}
                            {--sync : Process immediately instead of queueing}';

    protected $description = 'Re-extract and re-embed processed documents with the current embedding model';

    public function handle(VectorStore $vectors): int
    {
        $query = Document::query()->where('status', DocumentStatus::Processed);

        if ($this->option('document') !== null) {
            $query->whereKey($this->option('document'));
        }

        $documents = $query->get();

        if ($documents->isEmpty()) {
            $this->components->info('No processed documents to reindex.');

            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar($documents->count());
        $bar->start();

        foreach ($documents as $document) {
            $document->chunks()->delete();
            $vectors->forget($document);

            $document->forceFill([
                'status' => DocumentStatus::Pending,
                'progress' => 0,
                'chunk_count' => 0,
                'error_message' => null,
                'processed_at' => null,
            ])->save();

            $job = new ProcessDocumentJob($document);

            $this->option('sync') ? dispatch_sync($job) : dispatch($job);

            $bar->advance();
        }

        $bar->finish();
        $this->newLine();

        $this->components->info("Queued {$documents->count()} document(s) for re-embedding.");

        Notifier::make()
            ->type(NotificationType::SystemNotice)
            ->to(User::admins()->get())
            ->title('Reindexing started')
            ->body($documents->count().' document'.($documents->count() === 1 ? '' : 's').' '
                .($documents->count() === 1 ? 'was' : 'were').' queued for re-embedding with the current model.')
            ->link(route('dashboard'), 'Open dashboard')
            ->dedupe('system.reindex.'.now()->format('Y-m-d'))
            ->send();

        return self::SUCCESS;
    }
}
