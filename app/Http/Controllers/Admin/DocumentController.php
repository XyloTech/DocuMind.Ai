<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Services\AdminAudit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

/**
 * Review, correct and remove any knowledge source on the platform.
 *
 * Reads go through the `view` ability (any staff member), while mutations
 * require the `update`/`delete` abilities, which only administrators hold —
 * support can inspect a PDF but never change or remove somebody else's.
 */
class DocumentController extends Controller
{
    public function __construct(private readonly AdminAudit $audit) {}

    public function show(Request $request, Document $document): View
    {
        Gate::authorize('view', $document);

        return view('admin.document', [
            'document' => $document,
            'owner' => $document->user,
            'chunks' => $document->chunks()
                ->orderBy('chunk_index')
                ->limit(20)
                ->get(['chunk_index', 'chunk_text', 'page_from', 'page_to']),
        ]);
    }

    public function update(Request $request, Document $document): RedirectResponse
    {
        Gate::authorize('update', $document);

        $validated = $request->validate([
            'filename' => ['required', 'string', 'max:255'],
            'reason' => ['required', 'string', 'min:8', 'max:255'],
        ], [
            'filename.required' => 'Enter a name for the document.',
            'filename.max' => 'That name is too long. Keep it under 255 characters.',
        ]);

        $before = $document->filename;
        $document->update(['filename' => trim($validated['filename'])]);

        $this->audit->record(
            $request->user(),
            $document->user,
            'document.renamed',
            'Renamed a knowledge source',
            [
                'reason' => $validated['reason'],
                'document_id' => $document->getKey(),
                'before' => $before,
                'after' => $document->filename,
            ],
        );

        return back()->with('status', 'Document name saved and logged.');
    }

    public function destroy(Request $request, Document $document): RedirectResponse
    {
        Gate::authorize('delete', $document);

        $filename = $document->filename;

        Storage::disk((string) config('documents.disk'))->delete($document->file_path);

        $this->audit->record(
            $request->user(),
            $document->user,
            'document.deleted',
            'Deleted a knowledge source',
            [
                'document_id' => $document->getKey(),
                'filename' => $filename,
            ],
        );

        $document->delete();

        return redirect()
            ->route('admin.dashboard', ['tab' => 'knowledge'])
            ->with('status', "“{$filename}” was deleted and logged.");
    }
}
