<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Enums\NotificationType;
use App\Jobs\ProcessDocumentJob;
use App\Models\Document;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DocumentUploadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Queue::fake();
    }

    public function test_the_dashboard_renders_the_dropzone(): void
    {
        $response = $this->actingAs(User::factory()->create())->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee('Add knowledge documents', false);
        $response->assertSee('data-dropzone', false);
    }

    public function test_guests_cannot_upload_documents(): void
    {
        $response = $this->post(route('documents.store'), [
            'document' => $this->pdf(),
        ]);

        $response->assertRedirect(route('login'));
        $this->assertSame(0, Document::query()->count());
    }

    public function test_users_can_upload_a_pdf(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson(route('documents.store'), [
            'document' => $this->pdf(),
        ]);

        $response->assertStatus(202);
        $response->assertJsonPath('document.status', 'pending');
        $response->assertJsonPath('document.filename', 'handbook.pdf');

        $document = Document::query()->firstOrFail();

        Queue::assertPushed(
            ProcessDocumentJob::class,
            fn (ProcessDocumentJob $job): bool => $job->document->is($document),
        );

        $this->assertSame($user->getKey(), $document->user_id);
        $this->assertSame(DocumentStatus::Pending, $document->status);
        $this->assertSame(0, $document->progress);
        $this->assertNotEmpty($document->doc_hash);

        Storage::disk('local')->assertExists($document->file_path);

        $notification = UserNotification::query()->where('user_id', $user->getKey())->firstOrFail();

        $this->assertSame(NotificationType::KnowledgeUploaded, $notification->type);
        $this->assertSame('knowledge.uploaded.'.$document->getKey(), $notification->dedupe_key);
    }

    public function test_a_new_account_gets_an_isolated_workspace_before_uploading(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)
            ->postJson(route('documents.store'), ['document' => $this->pdf()])
            ->assertAccepted();

        $workspaceId = session('active_workspace_id');
        $document = Document::query()->firstOrFail();
        $this->assertNotNull($workspaceId);
        $this->assertSame($workspaceId, $document->workspace_id);
        $this->assertStringContainsString(
            'documents/workspaces/'.$workspaceId.'/users/'.$user->getKey(),
            $document->file_path,
        );
        Storage::disk('local')->assertExists($document->file_path);
    }

    public function test_non_pdf_files_are_rejected(): void
    {
        $response = $this->actingAs(User::factory()->create())->postJson(route('documents.store'), [
            'document' => UploadedFile::fake()->createWithContent('notes.txt', 'just some text'),
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('document');
        $this->assertSame(0, Document::query()->count());
    }

    public function test_files_without_a_real_pdf_header_are_rejected(): void
    {
        $response = $this->actingAs(User::factory()->create())->postJson(route('documents.store'), [
            'document' => UploadedFile::fake()->createWithContent('malware.pdf', str_repeat('nope ', 50)),
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('document');
        $this->assertSame(0, Document::query()->count());
    }

    public function test_oversized_pdfs_are_rejected(): void
    {
        config(['documents.max_size_kb' => 1]);

        $response = $this->actingAs(User::factory()->create())->postJson(route('documents.store'), [
            'document' => $this->pdf('big.pdf', $this->pdfContents(str_repeat('x', 4096))),
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('document');
        $this->assertSame(0, Document::query()->count());
    }

    public function test_the_per_user_quota_is_enforced(): void
    {
        config(['documents.max_per_user' => 1]);

        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson(route('documents.store'), ['document' => $this->pdf('first.pdf', $this->pdfContents('first'))])
            ->assertStatus(202);

        $response = $this->actingAs($user)->postJson(route('documents.store'), [
            'document' => $this->pdf('second.pdf', $this->pdfContents('second')),
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('document');
        $this->assertSame(1, Document::query()->count());
    }

    public function test_the_same_pdf_cannot_be_uploaded_twice(): void
    {
        $user = User::factory()->create();
        $content = $this->pdfContents('identical');

        $this->actingAs($user)
            ->postJson(route('documents.store'), ['document' => $this->pdf('copy.pdf', $content)])
            ->assertStatus(202);

        $response = $this->actingAs($user)->postJson(route('documents.store'), [
            'document' => $this->pdf('copy.pdf', $content),
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('document');
        $this->assertSame(1, Document::query()->count());
    }

    public function test_owners_can_poll_the_processing_status(): void
    {
        $document = Document::factory()->pending()->create();

        $response = $this->actingAs($document->user)->getJson(route('documents.status', $document));

        $response->assertOk();
        $response->assertJsonPath('status', 'pending');
        $response->assertJsonPath('progress', 0);
        $response->assertJsonPath('terminal', false);
    }

    public function test_processing_status_is_reported_once_terminal(): void
    {
        $document = Document::factory()->create();

        $this->actingAs($document->user)
            ->getJson(route('documents.status', $document))
            ->assertOk()
            ->assertJsonPath('status', 'processed')
            ->assertJsonPath('terminal', true);
    }

    public function test_owners_can_delete_a_document_and_its_file(): void
    {
        $document = Document::factory()->create();
        $user = $document->user;

        Storage::disk('local')->put($document->file_path, '%PDF-1.4 fake');

        $response = $this->actingAs($user)->deleteJson(route('documents.destroy', $document));

        $response->assertNoContent();
        $this->assertSame(0, Document::query()->count());
        Storage::disk('local')->assertMissing($document->file_path);
    }

    public function test_other_users_cannot_poll_or_delete_a_document(): void
    {
        $document = Document::factory()->create();
        $intruder = User::factory()->create();

        $this->actingAs($intruder)
            ->getJson(route('documents.status', $document))
            ->assertForbidden();

        $this->actingAs($intruder)
            ->deleteJson(route('documents.destroy', $document))
            ->assertForbidden();

        $this->assertSame(1, Document::query()->count());
    }

    public function test_the_dashboard_only_lists_the_current_users_documents(): void
    {
        $mine = Document::factory()->create(['filename' => 'mine.pdf']);
        Document::factory()->create(['filename' => 'someone-elses.pdf']);

        $response = $this->actingAs($mine->user)->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee('mine.pdf');
        $response->assertDontSee('someone-elses.pdf');
    }

    public function test_a_failed_document_can_be_retried(): void
    {
        $document = Document::factory()->failed()->create(['error_message' => 'The worker died.']);

        $response = $this->actingAs($document->user)
            ->postJson(route('documents.retry', $document));

        $response->assertStatus(202);

        $document->refresh();
        $this->assertSame(DocumentStatus::Pending, $document->status);
        $this->assertSame(0, $document->progress);
        $this->assertNull($document->error_message);

        Queue::assertPushed(
            ProcessDocumentJob::class,
            fn (ProcessDocumentJob $job): bool => $job->document->is($document),
        );
    }

    public function test_only_failed_documents_can_be_retried(): void
    {
        $document = Document::factory()->create();

        $this->actingAs($document->user)
            ->postJson(route('documents.retry', $document))
            ->assertStatus(409);

        $this->assertSame(DocumentStatus::Processed, $document->refresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_other_users_cannot_retry_a_document(): void
    {
        $document = Document::factory()->failed()->create();

        $this->actingAs(User::factory()->create())
            ->postJson(route('documents.retry', $document))
            ->assertForbidden();

        $this->assertSame(DocumentStatus::Failed, $document->refresh()->status);
    }

    public function test_a_document_left_queued_by_a_dead_worker_is_requeued_on_poll(): void
    {
        $document = Document::factory()->pending()->create();
        $this->stale($document, now()->subMinutes(10));

        $this->actingAs($document->user)
            ->getJson(route('documents.status', $document))
            ->assertOk()
            ->assertJsonPath('status', 'pending');

        Queue::assertPushed(
            ProcessDocumentJob::class,
            fn (ProcessDocumentJob $job): bool => $job->document->is($document),
        );
    }

    public function test_a_healthy_document_is_not_requeued_on_poll(): void
    {
        $document = Document::factory()->pending()->create();

        $this->actingAs($document->user)
            ->getJson(route('documents.status', $document))
            ->assertOk();

        Queue::assertNothingPushed();
    }

    /**
     * Backdate the row directly: touching the model would refresh updated_at.
     */
    private function stale(Document $document, $timestamp): void
    {
        DB::table('documents')->where('id', $document->getKey())->update(['updated_at' => $timestamp]);
    }

    private function pdf(?string $name = 'handbook.pdf', ?string $content = null): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            $name,
            $content ?? $this->pdfContents(),
        );
    }

    private function pdfContents(?string $seed = null): string
    {
        return "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n"
            .'% '.($seed ?? 'documind fixture')."\n"
            ."1 0 obj\n<< /Type /Catalog >>\nendobj\n"
            ."trailer\n<< /Size 1 >>\n%%EOF\n";
    }
}
