<?php

namespace Tests\Feature;

use App\Enums\ChatRole;
use App\Enums\NotificationType;
use App\Models\Document;
use App\Models\Site;
use App\Models\User;
use App\Models\UserNotification;
use App\Models\WidgetConversation;
use App\Models\WidgetMessage;
use App\Services\Ai\ChatClient;
use App\Services\Ai\ChatCompletion;
use App\Services\RAG\EmbeddingClient;
use App\Services\RAG\VectorStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WidgetChatTest extends TestCase
{
    use RefreshDatabase;

    /** The visitor id the real widget keeps in localStorage. */
    private const string VISITOR = 'visitor-abcdef123456';

    public function test_a_visitor_can_open_a_conversation_without_logging_in(): void
    {
        $site = $this->siteWithDocument();

        $response = $this->postJson(route('widget.conversations.store', $site->site_key), [
            'visitor_id' => self::VISITOR,
        ]);

        $response->assertOk()
            ->assertJsonStructure(['conversation_id', 'config' => ['bot_name', 'greeting', 'accent_color', 'position', 'theme', 'launcher_icon', 'blobatar' => ['seed', 'size', 'hue', 'tone', 'background', 'expression', 'animation']], 'quota'])
            ->assertJsonPath('config.bot_name', 'Assistant')
            ->assertJsonPath('config.theme', 'dark')
            ->assertJsonPath('config.launcher_icon', 'brand');

        $this->assertDatabaseHas('widget_conversations', [
            'site_id' => $site->getKey(),
            'visitor_id_hash' => hash_hmac('sha256', self::VISITOR, (string) config('app.key')),
        ]);

        $conversation = WidgetConversation::query()->firstOrFail();
        $this->assertSame(self::VISITOR, $conversation->visitor_id);
    }

    public function test_email_collection_requires_a_valid_email_and_explicit_consent_before_creating_a_conversation(): void
    {
        $site = $this->siteWithDocument();
        $site->update(['collect_email' => true]);

        $this->postJson(route('widget.conversations.store', $site->site_key), [
            'visitor_id' => self::VISITOR,
        ])->assertUnprocessable()->assertJsonValidationErrors(['email', 'email_consent']);

        $this->postJson(route('widget.conversations.store', $site->site_key), [
            'visitor_id' => self::VISITOR,
            'email' => 'not-an-email',
            'email_consent' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors('email');

        $this->assertSame(0, WidgetConversation::query()->count());
    }

    public function test_email_capture_encrypts_contact_data_and_records_consent_for_the_active_session(): void
    {
        $site = $this->siteWithDocument();
        $site->update(['collect_email' => true]);

        $response = $this->postJson(route('widget.conversations.store', $site->site_key), [
            'visitor_id' => self::VISITOR,
            'email' => 'Visitor@Example.com',
            'email_consent' => true,
        ]);

        $response->assertOk()
            ->assertJsonPath('email_captured', true)
            ->assertJsonPath('session_id', self::VISITOR);

        $conversation = WidgetConversation::query()->firstOrFail();
        $this->assertSame('visitor@example.com', $conversation->visitor_email);
        $this->assertNotNull($conversation->visitor_email_consent_at);
        $this->assertSame(hash_hmac('sha256', 'visitor@example.com', (string) config('app.key')), $conversation->visitor_email_hash);
        $this->assertSame('lead', $conversation->classification);
        $this->assertSame('pending', $conversation->follow_up_status);
        $this->assertNotSame('visitor@example.com', $conversation->getRawOriginal('visitor_email'));
        $this->assertArrayNotHasKey('visitor_email', $conversation->toArray());
        $this->assertDatabaseHas('widget_conversation_audit_logs', [
            'conversation_id' => $conversation->getKey(),
            'action' => 'visitor.email_consent_recorded',
        ]);

        $this->postJson(route('widget.conversations.store', $site->site_key), [
            'visitor_id' => self::VISITOR,
        ])->assertOk()->assertJsonPath('email_captured', true);

        $this->assertSame(1, WidgetConversation::query()->count());
    }

    public function test_a_visitor_cannot_send_messages_until_email_consent_is_saved(): void
    {
        $site = $this->siteWithDocument();
        $site->update(['collect_email' => true]);
        $conversation = $this->conversation($site);

        $this->postJson(
            route('widget.conversations.messages', [$site->site_key, $conversation->getKey()]),
            ['message' => 'Hello', 'visitor_id' => self::VISITOR],
        )->assertForbidden();

        $this->assertSame(0, $conversation->messages()->count());
    }

    public function test_public_config_refresh_returns_current_settings_without_opening_a_conversation(): void
    {
        $site = $this->siteWithDocument();
        $site->update([
            'bot_name' => 'Acme Helper',
            'theme' => 'light',
            'launcher_icon' => 'spark',
        ]);

        $response = $this->getJson(route('widget.config', $site->site_key));

        $response->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('config.bot_name', 'Acme Helper')
            ->assertJsonPath('config.theme', 'light')
            ->assertJsonPath('config.launcher_icon', 'spark');

        $this->assertSame(0, WidgetConversation::query()->count());
        $this->assertSame(0, $site->fresh()->messagesUsedThisPeriod());
    }

    public function test_the_widget_script_is_served_without_authentication(): void
    {
        $this->get('/widget.js')->assertOk()->assertHeader('content-type', 'application/javascript; charset=utf-8');
    }

    public function test_a_disabled_site_looks_missing(): void
    {
        $site = $this->siteWithDocument();
        $site->update(['enabled' => false]);

        $this->postJson(route('widget.conversations.store', $site->site_key), [
            'visitor_id' => self::VISITOR,
        ])->assertNotFound();
    }

    public function test_a_site_with_no_documents_looks_missing(): void
    {
        $site = Site::factory()->create();

        $this->postJson(route('widget.conversations.store', $site->site_key), [
            'visitor_id' => self::VISITOR,
        ])->assertNotFound();
    }

    public function test_an_unknown_site_key_is_not_found(): void
    {
        $this->postJson(route('widget.conversations.store', 'pk_does_not_exist'), [
            'visitor_id' => self::VISITOR,
        ])->assertNotFound();
    }

    public function test_a_visitor_message_streams_a_grounded_answer_with_sources(): void
    {
        $site = $this->siteWithDocument();
        $conversation = $this->conversation($site);

        $response = $this->postJson(
            route('widget.conversations.messages', [$site->site_key, $conversation->getKey()]),
            ['message' => 'When are invoices due?', 'visitor_id' => self::VISITOR],
        );

        $response->assertOk();

        $stream = $response->streamedContent();

        $this->assertStringContainsString('event: delta', $stream);
        $this->assertStringContainsString('event: sources', $stream);
        $this->assertStringContainsString('event: done', $stream);
        $this->assertStringNotContainsString('page_from', $stream);
        $this->assertStringNotContainsString('snippet', $stream);

        $answer = WidgetMessage::query()
            ->where('widget_conversation_id', $conversation->getKey())
            ->where('role', ChatRole::Assistant->value)
            ->firstOrFail();

        $this->assertNotSame('', $answer->content);
        $this->assertNotEmpty($answer->sourceBadges());
        $this->assertFalse($answer->was_refused);

        $transcript = $this->getJson(
            route('widget.conversations.show', [$site->site_key, $conversation->getKey()])
                .'?visitor_id='.self::VISITOR,
        );

        $transcript->assertOk()
            ->assertJsonPath('messages.1.sources.0.knowledge_used', true);
        $this->assertSame(['knowledge_used' => true], $transcript->json('messages.1.sources.0'));
    }

    public function test_the_stream_announces_each_phase_before_the_work_it_describes(): void
    {
        $site = $this->siteWithDocument();
        $conversation = $this->conversation($site);

        $response = $this->postJson(
            route('widget.conversations.messages', [$site->site_key, $conversation->getKey()]),
            ['message' => 'When are invoices due?', 'visitor_id' => self::VISITOR],
        );

        $response->assertOk();

        $stream = $response->streamedContent();

        $this->assertSame(['reading', 'searching', 'preparing'], $this->phases($stream));
        $this->assertLessThan(strpos($stream, 'event: delta'), strpos($stream, 'event: status'));
        $this->assertLessThan(strpos($stream, 'event: delta'), strpos($stream, '"phase":"searching"'));
    }

    public function test_a_greeting_announces_no_search_phase(): void
    {
        $site = $this->siteWithDocument();
        $conversation = $this->conversation($site);

        $response = $this->postJson(
            route('widget.conversations.messages', [$site->site_key, $conversation->getKey()]),
            ['message' => 'Hi there!', 'visitor_id' => self::VISITOR],
        );

        $response->assertOk();

        $this->assertSame(['reading', 'preparing'], $this->phases($response->streamedContent()));
    }

    public function test_recorded_latency_covers_the_whole_answer_not_just_the_setup(): void
    {
        $site = $this->siteWithDocument();
        $conversation = $this->conversation($site);
        $slow = new PromptSpyChatClient;
        $slow->reply = 'Invoices are due within thirty days.';
        $slow->delayMicroseconds = 80_000;
        $this->app->instance(ChatClient::class, $slow);

        $response = $this->postJson(
            route('widget.conversations.messages', [$site->site_key, $conversation->getKey()]),
            ['message' => 'When are invoices due?', 'visitor_id' => self::VISITOR],
        );

        $response->assertOk();
        $response->streamedContent();

        $answer = WidgetMessage::query()
            ->where('widget_conversation_id', $conversation->getKey())
            ->where('role', ChatRole::Assistant->value)
            ->firstOrFail();

        $this->assertGreaterThanOrEqual(50, $answer->latency_ms);
    }

    public function test_only_follow_up_visitor_messages_notify_the_site_owner(): void
    {
        $site = $this->siteWithDocument();
        $conversation = $this->conversation($site);

        $ask = function (string $message) use ($site, $conversation): void {
            $response = $this->postJson(
                route('widget.conversations.messages', [$site->site_key, $conversation->getKey()]),
                ['message' => $message, 'visitor_id' => self::VISITOR],
            );

            $response->assertOk();

            // The reply (and the owner's alert, which now waits for it) only
            // runs while the stream is consumed, exactly as the browser does.
            $response->streamedContent();
        };

        $ask('When are invoices due?');

        $this->assertSame(0, UserNotification::query()->count());

        $ask('And how long do refunds take?');

        $rows = UserNotification::query()->get();

        $this->assertCount(1, $rows);
        $this->assertSame(NotificationType::ConversationReplied, $rows[0]->type);
        $this->assertTrue($rows[0]->user->is($site->user));

        $asked = $conversation->messages()
            ->where('role', ChatRole::User->value)
            ->orderBy('id')
            ->pluck('id')
            ->all();

        $this->assertSame('conversation.replied.'.$asked[1], $rows[0]->dedupe_key);
    }

    public function test_a_greeting_is_answered_without_spending_context(): void
    {
        $site = $this->siteWithDocument();
        $conversation = $this->conversation($site);

        $this->postJson(
            route('widget.conversations.messages', [$site->site_key, $conversation->getKey()]),
            ['message' => 'hi', 'visitor_id' => self::VISITOR],
        )->assertOk();

        $answer = WidgetMessage::query()
            ->where('widget_conversation_id', $conversation->getKey())
            ->where('role', ChatRole::Assistant->value)
            ->firstOrFail();

        $this->assertNotSame(ChatClient::REFUSAL, $answer->content);
        $this->assertSame([], $answer->sourceBadges());
    }

    public function test_the_current_question_is_not_replayed_twice_in_the_prompt(): void
    {
        $site = $this->siteWithDocument();
        $conversation = $this->conversation($site);

        $spy = new PromptSpyChatClient;

        $this->app->instance(ChatClient::class, $spy);

        $response = $this->postJson(
            route('widget.conversations.messages', [$site->site_key, $conversation->getKey()]),
            ['message' => 'When are invoices due?', 'visitor_id' => self::VISITOR],
        );

        $response->assertOk();
        $this->assertStringContainsString('event: done', $response->streamedContent());

        $this->assertNotEmpty($spy->messages, 'the chat client was never called');

        $userTurns = array_values(array_filter(
            $spy->messages,
            fn (array $message): bool => $message['role'] === 'user',
        ));

        // The freshly stored question is appended by the prompt builder; it must
        // not also be replayed from history, or the model sees it twice in a row.
        $this->assertCount(1, $userTurns);
        $this->assertStringContainsString('When are invoices due?', $userTurns[0]['content']);
    }

    public function test_a_visitor_cannot_read_another_sites_conversation(): void
    {
        $site = $this->siteWithDocument();
        $other = $this->siteWithDocument();
        $conversation = $this->conversation($other);

        $this->postJson(
            route('widget.conversations.messages', [$site->site_key, $conversation->getKey()]),
            ['message' => 'What is in your private document?'],
        )->assertNotFound();
    }

    public function test_a_site_only_answers_from_its_own_documents(): void
    {
        $owner = User::factory()->create();

        $allowed = $this->document($owner, 'Invoices are due within thirty days of receipt.');
        $secret = Document::factory()->create(['user_id' => $owner->getKey()]);
        $this->persist($secret, 'The launch code is hunter2 and the passphrase is swordfish.');

        $site = Site::factory()->create(['user_id' => $owner->getKey()]);
        $site->documents()->attach($allowed);

        $conversation = $this->conversation($site);

        $this->postJson(
            route('widget.conversations.messages', [$site->site_key, $conversation->getKey()]),
            ['message' => 'What is the launch code?', 'visitor_id' => self::VISITOR],
        )->assertOk();

        $stream = WidgetMessage::query()
            ->where('widget_conversation_id', $conversation->getKey())
            ->where('role', ChatRole::Assistant->value)
            ->firstOrFail()
            ->content;

        $this->assertStringNotContainsString('hunter2', $stream);
    }

    public function test_the_quota_is_enforced_and_then_the_bot_stops(): void
    {
        $site = $this->siteWithDocument();
        $site->update(['monthly_quota' => 1]);

        $conversation = $this->conversation($site);

        $this->postJson(
            route('widget.conversations.messages', [$site->site_key, $conversation->getKey()]),
            ['message' => 'When are invoices due?', 'visitor_id' => self::VISITOR],
        )->assertOk();

        $this->postJson(
            route('widget.conversations.messages', [$site->site_key, $conversation->getKey()]),
            ['message' => 'And refunds?', 'visitor_id' => self::VISITOR],
        )->assertStatus(429)
            ->assertJsonPath('exhausted', true);

        $this->assertSame(1, $site->fresh()->messagesUsedThisPeriod());
    }

    public function test_feedback_is_recorded_for_analytics(): void
    {
        $site = $this->siteWithDocument();
        $conversation = $this->conversation($site);

        $answer = WidgetMessage::factory()->assistant('A helpful answer.')->create([
            'site_id' => $site->getKey(),
            'widget_conversation_id' => $conversation->getKey(),
        ]);

        $this->postJson(
            route('widget.conversations.feedback', [$site->site_key, $conversation->getKey()]),
            ['message_id' => $answer->getKey(), 'helpful' => true, 'visitor_id' => self::VISITOR],
        )->assertOk();

        $this->assertTrue($answer->fresh()->was_helpful);
    }

    public function test_a_visitor_cannot_reach_a_conversation_they_do_not_own(): void
    {
        $site = $this->siteWithDocument();
        $conversation = $this->conversation($site);

        // The site key is public, so the only thing separating one visitor's
        // transcript from another's is the visitor id they were issued.
        $this->postJson(
            route('widget.conversations.messages', [$site->site_key, $conversation->getKey()]),
            ['message' => 'Read their transcript', 'visitor_id' => 'visitor-someoneelse99'],
        )->assertNotFound();

        $this->getJson(
            route('widget.conversations.show', [$site->site_key, $conversation->getKey()])
                .'?visitor_id=visitor-someoneelse99',
        )->assertNotFound();

        $this->postJson(
            route('widget.conversations.feedback', [$site->site_key, $conversation->getKey()]),
            ['message_id' => 1, 'helpful' => false, 'visitor_id' => 'visitor-someoneelse99'],
        )->assertNotFound();

        $this->postJson(
            route('widget.conversations.messages', [$site->site_key, $conversation->getKey()]),
            ['message' => 'No visitor id at all'],
        )->assertStatus(422);

        $this->assertSame(0, $conversation->messages()->count());
    }

    public function test_the_owning_visitor_can_read_their_conversation(): void
    {
        $site = $this->siteWithDocument();
        $conversation = $this->conversation($site);

        $this->getJson(
            route('widget.conversations.show', [$site->site_key, $conversation->getKey()])
                .'?visitor_id='.self::VISITOR,
        )->assertOk()->assertJsonStructure(['config', 'messages', 'quota']);
    }

    public function test_reopening_a_conversation_reuses_the_existing_thread(): void
    {
        $site = $this->siteWithDocument();

        $first = $this->postJson(route('widget.conversations.store', $site->site_key), [
            'visitor_id' => self::VISITOR,
        ])->assertOk();

        $second = $this->postJson(route('widget.conversations.store', $site->site_key), [
            'visitor_id' => self::VISITOR,
        ])->assertOk();

        // A fresh row on every page load made the transcript unreadable and
        // gave an unauthenticated caller an unbounded write amplifier.
        $this->assertSame($first->json('conversation_id'), $second->json('conversation_id'));
        $this->assertSame(1, WidgetConversation::query()->count());
    }

    public function test_a_question_without_support_matches_gets_an_escalation_fallback(): void
    {
        config(['rag.min_similarity' => 1.0]);

        $site = $this->siteWithDocument();
        $conversation = $this->conversation($site);
        $spy = new PromptSpyChatClient;
        $spy->reply = 'I do not have enough confirmed support information. Please contact the support team.';
        $this->app->instance(ChatClient::class, $spy);

        $response = $this->postJson(
            route('widget.conversations.messages', [$site->site_key, $conversation->getKey()]),
            ['message' => 'What is the airspeed velocity of an unladen swallow?', 'visitor_id' => self::VISITOR],
        );

        $response->assertOk();
        $stream = $response->streamedContent();
        $this->assertStringContainsString('event: delta', $stream);
        $this->assertStringContainsString('event: done', $stream);

        $answer = WidgetMessage::query()
            ->where('widget_conversation_id', $conversation->getKey())
            ->where('role', ChatRole::Assistant->value)
            ->firstOrFail();

        $this->assertSame($spy->reply, $answer->content);
        $this->assertFalse($answer->was_refused);
        $this->assertSame([], $answer->sourceBadges());
        $this->assertStringContainsString('No relevant support knowledge was found', $spy->messages[0]['content']);
        $this->assertStringContainsString('do not answer unrelated general-knowledge questions', $spy->messages[0]['content']);

        $this->assertSame(1, $site->fresh()->messagesUsedThisPeriod());
        $this->assertSame($site->monthly_quota - 1, $site->fresh()->remainingQuota());
    }

    public function test_messages_are_length_limited(): void
    {
        $site = $this->siteWithDocument();
        $conversation = $this->conversation($site);

        $this->postJson(
            route('widget.conversations.messages', [$site->site_key, $conversation->getKey()]),
            ['message' => str_repeat('a', 1001), 'visitor_id' => self::VISITOR],
        )->assertStatus(422);
    }

    /**
     * The phases the server announced, in the order it announced them.
     *
     * @return list<string>
     */
    private function phases(string $stream): array
    {
        preg_match_all('/event: status\r?\ndata: \{"phase":"(\w+)"\}/', $stream, $matches);

        return $matches[1];
    }

    private function conversation(Site $site): WidgetConversation
    {
        return WidgetConversation::create([
            'site_id' => $site->getKey(),
            'visitor_id' => self::VISITOR,
        ]);
    }

    private function siteWithDocument(): Site
    {
        $user = User::factory()->create();
        $document = $this->document($user, 'Invoices are due within thirty days of receipt. Refunds take fourteen days.');

        $site = Site::factory()->create(['user_id' => $user->getKey()]);
        $site->documents()->attach($document);

        return $site;
    }

    private function document(User $user, string $text): Document
    {
        $document = Document::factory()->create(['user_id' => $user->getKey()]);
        $this->persist($document, $text);

        return $document;
    }

    private function persist(Document $document, string $text): void
    {
        app(VectorStore::class)->persist($document, [[
            'chunk_index' => 0,
            'text' => $text,
            'page_from' => 1,
            'page_to' => 1,
            'char_start' => 0,
            'char_end' => mb_strlen($text),
            'token_estimate' => (int) ceil(mb_strlen($text) / 4),
            'content_hash' => sha1($text),
            'vector' => app(EmbeddingClient::class)->embedOne($text),
        ]]);
    }
}

/**
 * Records the prompt it is handed so a test can assert its exact shape.
 */
class PromptSpyChatClient implements ChatClient
{
    /** @var list<array{role: string, content: string}> */
    public array $messages = [];

    public string $reply = 'Answer.';

    /** Simulated generation time, so a latency assertion has something to see. */
    public int $delayMicroseconds = 0;

    public function label(): string
    {
        return 'spy';
    }

    public function complete(array $messages, ?callable $onDelta = null): ChatCompletion
    {
        $this->messages = $messages;

        if ($this->delayMicroseconds > 0) {
            usleep($this->delayMicroseconds);
        }

        if ($onDelta !== null) {
            $onDelta($this->reply);
        }

        return new ChatCompletion($this->reply, 'spy', 1, 1);
    }
}
