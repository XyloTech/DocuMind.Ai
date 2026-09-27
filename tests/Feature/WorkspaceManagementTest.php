<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkspaceManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_documents_are_scoped_to_their_workspace_and_membership_is_enforced(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();

        $workspaceA = Workspace::factory()->for($owner, 'owner')->create();
        $workspaceB = Workspace::factory()->for($otherUser, 'owner')->create();

        $workspaceA->members()->attach($owner->id, ['role' => 'owner']);
        $workspaceB->members()->attach($otherUser->id, ['role' => 'owner']);

        $document = Document::factory()->create([
            'user_id' => $owner->id,
            'workspace_id' => $workspaceA->id,
        ]);

        $this->assertTrue($workspaceA->isMember($owner));
        $this->assertFalse($workspaceA->isMember($otherUser));
        $this->assertSame($workspaceA->id, $document->workspace_id);
        $this->assertFalse($workspaceA->isMember($otherUser));
        $this->assertNotSame($workspaceA->id, $workspaceB->id);
        $this->assertDatabaseHas('documents', [
            'id' => $document->id,
            'workspace_id' => $workspaceA->id,
        ]);
    }
}
