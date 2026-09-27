<?php

namespace Tests\Feature;

use App\Enums\NotificationCategory;
use App\Enums\NotificationType;
use App\Exceptions\ChatException;
use App\Models\Document;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\Notifier;
use App\Support\WidgetBundle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class NotificationEmittersTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_lifecycle_row_grows_into_its_outcome_instead_of_fanning_out(): void
    {
        $user = User::factory()->create();

        $this->raise(NotificationType::KnowledgeUploaded, $user, 'knowledge.uploaded.1', 'PDF received');
        $createdAt = UserNotification::query()->firstOrFail()->created_at;

        $this->raise(NotificationType::KnowledgeProcessed, $user, 'knowledge.uploaded.1', 'Document ready');

        $rows = UserNotification::query()->where('user_id', $user->getKey())->get();

        $this->assertCount(1, $rows);
        $this->assertSame(NotificationType::KnowledgeProcessed, $rows[0]->type);
        $this->assertSame(NotificationCategory::Knowledge, $rows[0]->category);
        $this->assertTrue($createdAt->equalTo($rows[0]->created_at));
    }

    public function test_a_lifecycle_row_that_has_been_read_stays_read_when_it_progresses(): void
    {
        $user = User::factory()->create();

        $this->raise(NotificationType::KnowledgeUploaded, $user, 'knowledge.uploaded.1', 'PDF received');
        UserNotification::query()->firstOrFail()->markAsRead();

        $this->raise(NotificationType::KnowledgeFailed, $user, 'knowledge.uploaded.1', 'Indexing stopped');

        $row = UserNotification::query()->firstOrFail();

        $this->assertNotNull($row->read_at);
        $this->assertSame(NotificationType::KnowledgeFailed, $row->type);
    }

    public function test_a_platform_outage_becomes_one_notice_on_the_third_failure_in_ten_minutes(): void
    {
        $admin = User::factory()->admin()->create();
        User::factory()->create();

        Notifier::integrationError(new ChatException('Provider timeout'));
        Notifier::integrationError(new ChatException('Provider timeout'));

        $this->assertSame(0, UserNotification::query()->count());

        Notifier::integrationError(new ChatException('Provider timeout'));
        Notifier::integrationError(new ChatException('Provider timeout'));

        $rows = UserNotification::query()->get();

        $this->assertCount(1, $rows);
        $this->assertSame(NotificationType::IntegrationError, $rows[0]->type);
        $this->assertTrue($rows[0]->user->is($admin));
    }

    public function test_a_one_off_unrelated_exception_is_not_a_platform_notice(): void
    {
        User::factory()->admin()->create();

        Notifier::integrationError(new RuntimeException('Validation failed'));
        Notifier::integrationError(new ChatException('Provider timeout'));

        $this->assertSame(0, UserNotification::query()->count());
    }

    public function test_an_unstorable_notification_never_breaks_the_event_that_raised_it(): void
    {
        Schema::drop('user_notifications');

        $rows = Notifier::make()
            ->type(NotificationType::KnowledgeUploaded)
            ->to(User::factory()->create())
            ->title('PDF received')
            ->send();

        $this->assertCount(0, $rows);
    }

    public function test_a_missing_widget_bundle_notifies_platform_admins_once_per_hour(): void
    {
        $admin = User::factory()->admin()->create();
        $owner = User::factory()->create();

        $hidden = $this->hideBuiltWidgetBundle();

        try {
            $this->get(route('widget.script'))->assertNotFound();
            $this->get(route('widget.script'))->assertNotFound();
        } finally {
            $this->restoreWidgetBundle($hidden);
        }

        $rows = UserNotification::query()->get();

        $this->assertCount(1, $rows);
        $this->assertSame(NotificationType::SystemNotice, $rows[0]->type);
        $this->assertTrue($rows[0]->user->is($admin));
        $this->assertFalse($rows[0]->user->is($owner));
    }

    public function test_reindexing_notifies_platform_admins_of_the_started_run(): void
    {
        Queue::fake();
        User::factory()->admin()->create();
        $document = Document::factory()->create();

        $this->artisan('rag:reindex')->assertSuccessful();

        $rows = UserNotification::query()->get();

        $this->assertCount(1, $rows);
        $this->assertSame(NotificationType::SystemNotice, $rows[0]->type);
        $this->assertStringContainsString('1 document was queued', (string) $rows[0]->body);
        $this->assertDatabaseHas('documents', ['id' => $document->getKey(), 'status' => 'pending']);
    }

    public function test_a_reindex_that_queues_nothing_stays_silent(): void
    {
        User::factory()->admin()->create();
        Document::factory()->pending()->create();

        $this->artisan('rag:reindex')->assertSuccessful();

        $this->assertSame(0, UserNotification::query()->count());
    }

    private function raise(NotificationType $type, User $user, string $dedupe, string $title): void
    {
        Notifier::make()
            ->type($type)
            ->to($user)
            ->title($title)
            ->body('Details of the event.')
            ->dedupe($dedupe)
            ->send();
    }

    /**
     * Temporarily move the built bundle aside so the 404 path can be exercised
     * without deleting a real build artifact.
     *
     * @return array<string, string>
     */
    private function hideBuiltWidgetBundle(): array
    {
        $hidden = [];

        foreach ([WidgetBundle::publicPath(), WidgetBundle::storagePath()] as $path) {
            if (is_file($path)) {
                rename($path, $path.'.notification-test');
                $hidden[$path] = $path.'.notification-test';
            }
        }

        return $hidden;
    }

    /**
     * @param  array<string, string>  $hidden
     */
    private function restoreWidgetBundle(array $hidden): void
    {
        foreach ($hidden as $path => $backup) {
            rename($backup, $path);
        }
    }
}
