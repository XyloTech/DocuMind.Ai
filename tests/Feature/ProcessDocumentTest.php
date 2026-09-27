<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Jobs\ProcessDocumentJob;
use App\Models\Document;
use App\Models\DocumentChunk;
use App\Models\User;
use App\Services\Pdf\PdfTextExtractor;
use App\Services\RAG\EmbeddingClient;
use App\Services\RAG\SimilaritySearch;
use App\Services\RAG\VectorStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProcessDocumentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    public function test_an_uploaded_pdf_is_processed_into_searchable_chunks(): void
    {
        $document = $this->upload('policy.pdf', $this->pdfWithText('DocuMind invoices are due within thirty days of receipt.'));

        $this->assertSame(DocumentStatus::Processed, $document->status);
        $this->assertSame(1, $document->page_count);
        $this->assertSame(100, $document->progress);
        $this->assertSame(1, $document->chunk_count);
        $this->assertNotNull($document->processed_at);
        $this->assertNull($document->error_message);

        $chunk = DocumentChunk::query()->where('document_id', $document->getKey())->firstOrFail();

        $this->assertStringContainsString('invoices are due', $chunk->chunk_text);
        $this->assertSame(1, $chunk->page_from);
        $this->assertCount(1536, $chunk->embedding);
        $this->assertEqualsWithDelta(1.0, $this->norm($chunk->embedding), 1e-4);
    }

    public function test_a_processed_document_can_be_ranked_against_a_query(): void
    {
        $document = $this->upload('policy.pdf', $this->pdfWithText('The refund window closes fourteen days after purchase.'));

        $chunks = app(VectorStore::class)->loadForDocument($document->getKey());

        $this->assertNotEmpty($chunks);

        $query = app(EmbeddingClient::class)->embedOne('How long is the refund window?');
        $ranked = app(SimilaritySearch::class)->rank($query, $chunks, (int) config('rag.top_k'));

        $this->assertNotEmpty($ranked);
        $this->assertStringContainsString('refund window', $ranked[0]['chunk']['text']);
        $this->assertLessThanOrEqual(1.0, $ranked[0]['score']);
    }

    public function test_reprocessing_a_document_does_not_duplicate_chunks(): void
    {
        $document = $this->upload('policy.pdf', $this->pdfWithText('DocuMind invoices are due within thirty days of receipt.'));

        $document->forceFill(['status' => DocumentStatus::Pending])->save();

        (new ProcessDocumentJob($document->fresh()))->handle(
            app(PdfTextExtractor::class),
            app(EmbeddingClient::class),
            app(VectorStore::class),
        );

        $this->assertSame(1, DocumentChunk::query()->where('document_id', $document->getKey())->count());
        $this->assertSame(DocumentStatus::Processed, $document->fresh()->status);
    }

    public function test_a_pdf_without_selectable_text_fails_with_a_clear_message(): void
    {
        $document = $this->upload('scanned.pdf', $this->pdfWithoutText());

        $this->assertSame(DocumentStatus::Failed, $document->status);
        $this->assertSame(100, $document->progress);
        $this->assertStringContainsString('no selectable text', (string) $document->error_message);
        $this->assertSame(0, DocumentChunk::query()->where('document_id', $document->getKey())->count());
    }

    public function test_the_status_endpoint_reports_a_finished_document_as_terminal(): void
    {
        $document = $this->upload('policy.pdf', $this->pdfWithText('DocuMind invoices are due within thirty days of receipt.'));

        $this->actingAs($document->user)
            ->getJson(route('documents.status', $document))
            ->assertOk()
            ->assertJsonPath('status', 'processed')
            ->assertJsonPath('terminal', true)
            ->assertJsonPath('progress', 100);
    }

    private function upload(string $name, string $contents): Document
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson(route('documents.store'), [
            'document' => UploadedFile::fake()->createWithContent($name, $contents),
        ]);

        $response->assertStatus(202);

        return Document::query()->where('user_id', $user->getKey())->firstOrFail();
    }

    /**
     * @param  list<float>  $vector
     */
    private function norm(array $vector): float
    {
        $sum = 0.0;

        foreach ($vector as $value) {
            $sum += $value * $value;
        }

        return sqrt($sum);
    }

    private function pdfWithText(string $text): string
    {
        return $this->pdf("BT /F1 12 Tf 72 720 Td ({$text}) Tj ET\n");
    }

    private function pdfWithoutText(): string
    {
        return $this->pdf("q\n0 0 1 rg\n0 0 612 792 re f\nQ\n");
    }

    private function pdf(string $stream): string
    {
        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R '
                .'/Resources << /Font << /F1 5 0 R >> >> >>',
            4 => '<< /Length '.strlen($stream)." >>\nstream\n".$stream.'endstream',
            5 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ];

        $pdf = "%PDF-1.4\n";
        $offsets = [];

        foreach ($objects as $number => $body) {
            $offsets[$number] = strlen($pdf);
            $pdf .= "{$number} 0 obj\n{$body}\nendobj\n";
        }

        $startXref = strlen($pdf);
        $size = count($objects) + 1;

        $pdf .= "xref\n0 {$size}\n";
        $pdf .= "0000000000 65535 f \n";

        foreach ($objects as $number => $body) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$number]);
        }

        $pdf .= "trailer\n<< /Size {$size} /Root 1 0 R >>\n";
        $pdf .= "startxref\n{$startXref}\n%%EOF\n";

        return $pdf;
    }
}
