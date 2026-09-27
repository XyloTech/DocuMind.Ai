<?php

namespace Tests\Feature;

use App\Enums\ChatRole;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\Document;
use App\Models\PrivacyAuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PrivacySettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_new_account_is_shown_the_consent_prompt(): void
    {
        $user = User::factory()->unconsented()->create();

        $page = $this->actingAs($user)->get(route('dashboard'));

        $page->assertOk();
        $page->assertSee('Your data, your choice', false);
        $page->assertSee('Save my chat history');
        $page->assertSee('improve the model');
        $page->assertSee(route('privacy.policy'), false);
    }

    public function test_a_consent_answer_is_recorded_with_both_choices_off_by_default(): void
    {
        $user = User::factory()->unconsented()->create();

        $this->actingAs($user)
            ->post(route('settings.privacy.consent'))
            ->assertRedirect();

        $user->refresh();

        $this->assertTrue($user->hasGivenConsent());
        $this->assertFalse($user->allowsHistoryStorage());
        $this->assertFalse($user->allowsTraining());
        $this->assertSame((string) config('privacy.consent_version'), $user->privacy_consent_version);

        $this->assertDatabaseHas('privacy_audit_logs', [
            'user_id' => $user->getKey(),
            'action' => 'consent.recorded',
        ]);
    }

    public function test_switching_history_on_lets_the_account_start_a_conversation(): void
    {
        $user = User::factory()->unconsented()->create();
        $document = Document::factory()->create(['user_id' => $user->getKey()]);

        $blocked = $this->actingAs($user)->postJson(route('chats.store'), [
            'document_id' => $document->getKey(),
        ]);

        $blocked->assertStatus(409);
        $this->assertDatabaseMissing('chats', ['user_id' => $user->getKey()]);

        $this->post(route('settings.privacy.consent'), ['store_chat_history' => '1']);

        $allowed = $this->actingAs($user)->postJson(route('chats.store'), [
            'document_id' => $document->getKey(),
        ]);

        $allowed->assertCreated();
        $this->assertDatabaseHas('chats', ['user_id' => $user->getKey()]);
    }

    public function test_an_unconsented_account_cannot_post_a_message(): void
    {
        $user = User::factory()->unconsented()->create();
        $document = Document::factory()->create(['user_id' => $user->getKey()]);
        $chat = Chat::factory()->create([
            'user_id' => $user->getKey(),
            'document_id' => $document->getKey(),
        ]);

        $response = $this->actingAs($user)
            ->withHeaders(['Accept' => 'text/event-stream'])
            ->post(route('chats.messages', $chat), ['message' => 'What are the terms?']);

        $response->assertStatus(409);
        $this->assertSame(0, $chat->messages()->count());
    }

    public function test_settings_changes_are_audited(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->patch(route('settings.privacy.update'), [
            'store_chat_history' => '1',
            'allow_model_training' => '1',
            'chat_retention_days' => '30',
        ])->assertRedirect();

        $user->refresh();

        $this->assertTrue($user->allowsHistoryStorage());
        $this->assertTrue($user->allowsTraining());
        $this->assertSame(30, $user->chat_retention_days);

        $log = PrivacyAuditLog::query()
            ->where('user_id', $user->getKey())
            ->where('action', 'settings.updated')
            ->first();

        $this->assertNotNull($log);
        $this->assertStringContainsString('allow_model_training', $log->summary);
        $this->assertNotSame($user->email, $log->summary, 'the audit summary must not carry identity data');

        $stored = DB::table('privacy_audit_logs')->where('id', $log->getKey())->value('details');

        $this->assertIsString($stored);
        $this->assertStringNotContainsString(
            'allow_model_training',
            $stored,
            'the audit payload must be encrypted at rest',
        );
    }

    public function test_an_offered_retention_window_only_accepts_the_listed_values(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->patch(route('settings.privacy.update'), ['chat_retention_days' => '7'])
            ->assertSessionHasErrors('chat_retention_days');

        $this->actingAs($user)
            ->patch(route('settings.privacy.update'), ['chat_retention_days' => ''])
            ->assertRedirect();

        $this->assertNull($user->fresh()->chat_retention_days);
    }

    public function test_withdrawing_consent_switches_everything_off_and_reopens_the_prompt(): void
    {
        $user = User::factory()->create();
        $user->forceFill([
            'allow_model_training' => true,
            'store_chat_history' => true,
        ])->save();

        $this->actingAs($user)->post(route('settings.privacy.revoke'))->assertRedirect(route('settings.privacy'));

        $user->refresh();

        $this->assertFalse($user->hasGivenConsent());
        $this->assertFalse($user->allowsHistoryStorage());
        $this->assertFalse($user->allowsTraining());
        $this->assertDatabaseHas('privacy_audit_logs', [
            'user_id' => $user->getKey(),
            'action' => 'consent.revoked',
        ]);
    }

    public function test_the_data_export_contains_conversations_but_no_credentials(): void
    {
        $user = User::factory()->create();
        $document = Document::factory()->create(['user_id' => $user->getKey()]);
        $chat = Chat::factory()->create([
            'user_id' => $user->getKey(),
            'document_id' => $document->getKey(),
            'title' => 'Renewal terms',
        ]);
        ChatMessage::factory()->create([
            'chat_id' => $chat->getKey(),
            'role' => ChatRole::User,
            'content' => 'When is the renewal date?',
        ]);

        $response = $this->actingAs($user)->get(route('settings.privacy.export'));

        $response->assertOk();
        $response->assertDownload('documind-export-'.now()->format('Y-m-d').'.json');

        $payload = json_decode($response->streamedContent(), true);

        $this->assertIsArray($payload);
        $this->assertSame($user->email, $payload['account']['email']);
        $this->assertSame('Renewal terms', $payload['conversations'][0]['title']);
        $this->assertSame('When is the renewal date?', $payload['conversations'][0]['messages'][0]['content']);
        $this->assertStringNotContainsString($user->password, $response->streamedContent());

        $this->assertDatabaseHas('privacy_audit_logs', [
            'user_id' => $user->getKey(),
            'action' => 'data.exported',
        ]);
    }

    public function test_deleting_data_needs_a_recent_google_session_and_the_typed_confirmation(): void
    {
        $user = User::factory()->create();
        $document = Document::factory()->create(['user_id' => $user->getKey()]);
        $chat = Chat::factory()->create([
            'user_id' => $user->getKey(),
            'document_id' => $document->getKey(),
        ]);
        ChatMessage::factory()->create(['chat_id' => $chat->getKey()]);

        $this->actingAs($user)
            ->delete(route('settings.privacy.destroy'), [
                'confirmation' => 'DELETE',
            ])
            ->assertSessionHasErrors('confirmation');

        $this->actingAs($user)->withSession(['firebase_authenticated_at' => now()->getTimestamp()])
            ->delete(route('settings.privacy.destroy'), [
                'confirmation' => 'delete',
            ])
            ->assertSessionHasErrors('confirmation');

        $this->assertDatabaseHas('chats', ['id' => $chat->getKey()]);

        $this->actingAs($user)->withSession(['firebase_authenticated_at' => now()->getTimestamp()])
            ->delete(route('settings.privacy.destroy'), [
                'confirmation' => 'DELETE',
            ])
            ->assertRedirect(route('dashboard'));

        $this->assertDatabaseMissing('chat_messages', ['chat_id' => $chat->getKey()]);
        $this->assertDatabaseMissing('chats', ['id' => $chat->getKey()]);
        $this->assertDatabaseMissing('documents', ['id' => $document->getKey()]);
        $this->assertDatabaseHas('users', ['id' => $user->getKey()]);

        $this->assertDatabaseHas('privacy_audit_logs', [
            'user_id' => $user->getKey(),
            'action' => 'data.deleted',
        ]);
    }

    public function test_retention_pruning_removes_only_expired_conversations(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['chat_retention_days' => 30])->save();

        $expired = Chat::factory()->create([
            'user_id' => $user->getKey(),
            'last_message_at' => now()->subDays(45),
        ]);
        $recent = Chat::factory()->create([
            'user_id' => $user->getKey(),
            'last_message_at' => now()->subDays(3),
        ]);

        $this->artisan('privacy:prune')->assertSuccessful();

        $this->assertDatabaseMissing('chats', ['id' => $expired->getKey()]);
        $this->assertDatabaseHas('chats', ['id' => $recent->getKey()]);
        $this->assertDatabaseHas('privacy_audit_logs', [
            'user_id' => $user->getKey(),
            'action' => 'retention.pruned',
        ]);
    }

    public function test_the_training_export_needs_consent_and_anonymises_what_it_takes(): void
    {
        Storage::fake('local');

        $optedIn = User::factory()->create();
        $optedIn->forceFill(['allow_model_training' => true])->save();

        $chat = Chat::factory()->create(['user_id' => $optedIn->getKey()]);
        ChatMessage::factory()->create([
            'chat_id' => $chat->getKey(),
            'role' => ChatRole::User,
            'content' => 'My email is '.$optedIn->email.' and my phone is +44 20 7946 0958.',
        ]);
        ChatMessage::factory()->assistant('Renewals are due in thirty days.', [
            ['score' => 0.9, 'page_from' => 3, 'page_to' => 3, 'snippet' => 'Renewals are due in thirty days.'],
        ])->create(['chat_id' => $chat->getKey()]);

        $optedOut = User::factory()->create();
        $optedOutChat = Chat::factory()->create(['user_id' => $optedOut->getKey()]);
        ChatMessage::factory()->create([
            'chat_id' => $optedOutChat->getKey(),
            'role' => ChatRole::User,
            'content' => 'Secret plan from an account that never opted in.',
        ]);

        $this->artisan('privacy:training-export', ['--output' => 'training/dataset.jsonl'])
            ->assertSuccessful();

        $dataset = Storage::disk('local')->get('training/dataset.jsonl');

        $this->assertStringContainsString('[EMAIL]', $dataset);
        $this->assertStringNotContainsString($optedIn->email, $dataset);
        $this->assertStringNotContainsString('7946 0958', $dataset);
        $this->assertStringNotContainsString('Secret plan', $dataset, 'non-consenting accounts are never exported');

        $this->assertDatabaseHas('privacy_audit_logs', [
            'user_id' => $optedIn->getKey(),
            'action' => 'training.exported',
        ]);
        $this->assertDatabaseMissing('privacy_audit_logs', [
            'user_id' => $optedOut->getKey(),
            'action' => 'training.exported',
        ]);
    }

    public function test_the_privacy_policy_is_readable_without_an_account(): void
    {
        $response = $this->get('/privacy-policy');

        $response->assertOk();
        $response->assertSee('Privacy policy');
        $response->assertSee('never use your email address');
    }

    public function test_an_uploaded_document_is_deleted_with_the_account_data(): void
    {
        Storage::fake((string) config('documents.disk'));

        $user = User::factory()->create();
        $document = Document::factory()->create([
            'user_id' => $user->getKey(),
            'file_path' => 'documents/contract.pdf',
        ]);

        Storage::disk((string) config('documents.disk'))->put($document->file_path, '%PDF-1.4 fake');

        $this->actingAs($user)->withSession(['firebase_authenticated_at' => now()->getTimestamp()])
            ->delete(route('settings.privacy.destroy'), [
                'confirmation' => 'DELETE',
            ])
            ->assertRedirect(route('dashboard'));

        Storage::disk((string) config('documents.disk'))->assertMissing($document->file_path);
        $this->assertDatabaseMissing('documents', ['id' => $document->getKey()]);
    }
}
