<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminDocumentReviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_and_support_can_review_a_document_but_analysts_and_customers_cannot(): void
    {
        $document = Document::factory()->create(['filename' => 'refunds.pdf']);

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->get(route('admin.documents.show', $document))
            ->assertOk()
            ->assertSee('refunds.pdf');

        $support = User::factory()->support()->create();
        $this->actingAs($support)->get(route('admin.documents.show', $document))->assertOk();

        $analyst = User::factory()->analyst()->create();
        $this->actingAs($analyst)->get(route('admin.documents.show', $document))->assertForbidden();

        $customer = User::factory()->create();
        $this->actingAs($customer)->get(route('admin.documents.show', $document))->assertForbidden();
    }

    public function test_the_review_page_shows_the_indexed_sections(): void
    {
        $document = Document::factory()->create([
            'filename' => 'handbook.pdf',
            'chunk_count' => 1,
            'suggested_questions' => ['How do refunds work?'],
        ]);
        $document->chunks()->create([
            'chunk_index' => 0,
            'chunk_text' => 'Refunds are issued within five business days.',
            'embedding' => [0.1, 0.2, 0.3],
            'embedding_norm' => 0.37,
            'page_from' => 4,
            'page_to' => 5,
            'char_start' => 0,
            'char_end' => 44,
            'token_estimate' => 11,
            'content_hash' => sha1('Refunds are issued within five business days.'),
        ]);

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('admin.documents.show', $document))
            ->assertOk()
            ->assertSee('Refunds are issued within five business days.')
            ->assertSee('How do refunds work?');
    }

    public function test_only_an_administrator_can_rename_or_delete_a_document(): void
    {
        Storage::fake((string) config('documents.disk'));

        $document = Document::factory()->create(['filename' => 'original.pdf']);
        $support = User::factory()->support()->create();

        $this->actingAs($support)
            ->patch(route('admin.documents.update', $document), [
                'filename' => 'renamed.pdf',
                'reason' => 'Customer asked for a clearer name',
            ])
            ->assertForbidden();

        $this->actingAs($support)
            ->delete(route('admin.documents.destroy', $document))
            ->assertForbidden();

        $this->assertSame('original.pdf', $document->fresh()->filename);

        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->patch(route('admin.documents.update', $document), [
                'filename' => 'renamed.pdf',
                'reason' => 'Customer asked for a clearer name',
            ])
            ->assertRedirect();

        $this->assertSame('renamed.pdf', $document->fresh()->filename);

        $this->assertDatabaseHas('admin_audit_logs', [
            'actor_id' => $admin->getKey(),
            'action' => 'document.renamed',
        ]);

        $this->actingAs($admin)
            ->delete(route('admin.documents.destroy', $document))
            ->assertRedirect(route('admin.dashboard', ['tab' => 'knowledge']));

        $this->assertNull(Document::query()->find($document->getKey()));

        $this->assertDatabaseHas('admin_audit_logs', [
            'actor_id' => $admin->getKey(),
            'action' => 'document.deleted',
        ]);
    }

    public function test_renaming_requires_a_logged_reason(): void
    {
        $document = Document::factory()->create();
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->from(route('admin.documents.show', $document))
            ->patch(route('admin.documents.update', $document), [
                'filename' => 'clean.pdf',
                'reason' => 'short',
            ])
            ->assertRedirect(route('admin.documents.show', $document))
            ->assertSessionHasErrors('reason');

        $this->assertSame($document->filename, $document->fresh()->filename);
    }

    public function test_the_policy_gives_staff_read_access_and_admins_write_access(): void
    {
        $document = Document::factory()->create();

        $support = User::factory()->support()->create();
        $analyst = User::factory()->analyst()->create();
        $admin = User::factory()->admin()->create();
        $customer = User::factory()->create();

        $this->assertTrue($support->can('view', $document));
        $this->assertFalse($support->can('update', $document));
        $this->assertFalse($support->can('delete', $document));

        $this->assertTrue($analyst->can('view', $document));
        $this->assertFalse($analyst->can('update', $document));

        $this->assertTrue($admin->can('update', $document));
        $this->assertTrue($admin->can('delete', $document));

        $this->assertTrue($document->user->can('view', $document));
        $this->assertFalse($customer->can('view', $document));
        $this->assertFalse($customer->can('delete', $document));
    }
}
