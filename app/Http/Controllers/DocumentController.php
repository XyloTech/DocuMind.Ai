<?php

namespace App\Http\Controllers;

use App\Enums\DocumentStatus;
use App\Enums\NotificationType;
use App\Jobs\ProcessDocumentJob;
use App\Models\Document;
use App\Models\User;
use App\Services\Notifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class DocumentController extends Controller
{
    /**
     * Accept a PDF upload from the dashboard dropzone.
     */
    public function store(Request $request): JsonResponse|RedirectResponse
    {
        $this->ensureQuotaAvailable($request->user());

        $validated = $request->validate([
            'document' => ['required', 'file', 'mimes:pdf', 'max:'.config('documents.max_size_kb')],
        ], [
            'document.required' => 'Choose a PDF to upload.',
            'document.file' => 'Choose a PDF to upload.',
            'document.mimes' => 'Only PDF files can be uploaded.',
            'document.max' => 'That PDF is too large. The limit is '
                .(int) (config('documents.max_size_kb') / 1024).' MB.',
        ]);

        $file = $request->file('document');
        $absolutePath = (string) $file->getRealPath();

        if (! $this->looksLikePdf($absolutePath)) {
            throw ValidationException::withMessages([
                'document' => 'That file does not start with a valid PDF header.',
            ]);
        }

        $hash = hash_file('sha256', $absolutePath);

        $this->ensureNotAlreadyUploaded($request->user(), $hash);

        $workspaceId = $request->session()->get('active_workspace_id');

        abort_if($workspaceId === null, 403, 'Choose a workspace before uploading support documents.');

        $path = $file->store(
            'documents/workspaces/'.$workspaceId.'/users/'.$request->user()->getKey(),
            (string) config('documents.disk'),
        );

        if ($path === false || $path === null) {
            throw ValidationException::withMessages([
                'document' => 'The upload could not be stored. Please try again.',
            ]);
        }

        $document = Document::create([
            'user_id' => $request->user()->getKey(),
            'workspace_id' => $workspaceId,
            'filename' => mb_substr(basename($file->getClientOriginalName()), 0, 255),
            'file_path' => $path,
            'mime_type' => (string) $file->getMimeType(),
            'size_bytes' => (int) $file->getSize(),
            'status' => DocumentStatus::Pending,
            'progress' => 0,
            'doc_hash' => $hash,
        ]);

        // The upload, every indexing milestone and the final outcome all share
        // this dedupe key, so one document reads as a single evolving entry
        // instead of a trail of near-identical rows.
        Notifier::make()
            ->type(NotificationType::KnowledgeUploaded)
            ->to($document->user)
            ->title('PDF received')
            ->body('"'.$document->filename.'" was uploaded and queued for indexing.')
            ->link(route('dashboard'), 'View document')
            ->workspace($document->workspace_id)
            ->dedupe('knowledge.uploaded.'.$document->getKey())
            ->send();

        ProcessDocumentJob::dispatch($document);

        if ($request->expectsJson()) {
            return response()->json([
                'document' => $document,
                'html' => $this->renderCard($document),
            ], 202);
        }

        return redirect()
            ->route('dashboard')
            ->with('status', "“{$document->filename}” uploaded and queued for processing.");
    }

    /**
     * Poll the processing state of a document.
     */
    public function status(Request $request, Document $document): JsonResponse
    {
        Gate::authorize('view', $document);

        $this->redispatchLostJob($document);

        return response()->json([
            'id' => $document->getKey(),
            'status' => $document->status->value,
            'progress' => $document->progress,
            'terminal' => $document->status->isTerminal(),
            'html' => $this->renderCard($document),
        ]);
    }

    /**
     * Queue a failed document for another attempt.
     */
    public function retry(Request $request, Document $document): JsonResponse|RedirectResponse
    {
        Gate::authorize('update', $document);

        abort_unless(
            $document->status === DocumentStatus::Failed,
            409,
            'Only documents that failed processing can be retried.',
        );

        $document->forceFill([
            'status' => DocumentStatus::Pending,
            'progress' => 0,
            'error_message' => null,
        ])->save();

        ProcessDocumentJob::dispatch($document);

        if ($request->expectsJson()) {
            return response()->json([
                'document' => $document,
                'html' => $this->renderCard($document),
            ], 202);
        }

        return redirect()
            ->route('dashboard')
            ->with('status', "Retrying “{$document->filename}”.");
    }

    /**
     * Remove a document and its stored file.
     */
    public function destroy(Request $request, Document $document): Response
    {
        Gate::authorize('delete', $document);

        Storage::disk((string) config('documents.disk'))->delete($document->file_path);

        $document->delete();

        if ($request->expectsJson()) {
            return response()->noContent();
        }

        return redirect()
            ->route('dashboard')
            ->with('status', "“{$document->filename}” was deleted.");
    }

    private function looksLikePdf(string $absolutePath): bool
    {
        $header = file_get_contents($absolutePath, false, null, 0, 5);

        return $header === '%PDF-';
    }

    /**
     * Re-queue a document whose worker disappeared mid-flight.
     *
     * A restarted queue container, a crashed worker or a job lost to a
     * database blip all leave the card frozen on "Queued" with no recovery on
     * the horizon. The job is unique, so this is safe to attempt repeatedly:
     * while a worker already holds it the dispatch is dropped, and when the
     * queue really is empty it puts the document back in line. The throttle
     * only exists to keep a 1.5s status poll from churning the cache.
     */
    private function redispatchLostJob(Document $document): void
    {
        if ($document->status->isTerminal()) {
            return;
        }

        if ($document->updated_at === null || $document->updated_at->isAfter(now()->subMinutes(3))) {
            return;
        }

        if (! Cache::add('documents:redispatch:'.$document->getKey(), true, 60)) {
            return;
        }

        ProcessDocumentJob::dispatch($document);
    }

    private function ensureQuotaAvailable(User $user): void
    {
        $max = (int) config('documents.max_per_user');

        $workspaceId = request()->session()->get('active_workspace_id');

        $used = Document::query()
            ->where('user_id', $user->getKey())
            ->when($workspaceId !== null, fn ($query) => $query->where('workspace_id', $workspaceId))
            ->count();

        if ($used >= $max) {
            throw ValidationException::withMessages([
                'document' => "You have reached your limit of {$max} documents. Delete one before uploading another.",
            ]);
        }
    }

    private function ensureNotAlreadyUploaded(User $user, string $hash): void
    {
        $workspaceId = request()->session()->get('active_workspace_id');

        $existing = Document::query()
            ->where('user_id', $user->getKey())
            ->when($workspaceId !== null, fn ($query) => $query->where('workspace_id', $workspaceId))
            ->where('doc_hash', $hash)
            ->first();

        if ($existing === null) {
            return;
        }

        if ($existing->status !== DocumentStatus::Failed) {
            throw ValidationException::withMessages([
                'document' => 'You have already uploaded this exact PDF.',
            ]);
        }

        Storage::disk((string) config('documents.disk'))->delete($existing->file_path);
        $existing->delete();
    }

    private function renderCard(Document $document): string
    {
        return view('documents.card', ['document' => $document])->render();
    }
}
