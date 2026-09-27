<?php

namespace Tests\Feature;

use App\Enums\ChatRole;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChatFeedbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_can_rate_an_answer_and_the_vote_is_stored(): void
    {
        [$user, $chat, $message] = $this->assistantInOwnChat();

        $this->actingAs($user)
            ->post(route('chats.feedback', [$chat, $message]), ['helpful' => true])
            ->assertOk()
            ->assertJson(['ok' => true, 'was_helpful' => true]);

        $this->assertTrue($message->fresh()->was_helpful);
    }

    public function test_a_down_vote_is_stored_as_not_helpful(): void
    {
        [$user, $chat, $message] = $this->assistantInOwnChat();

        $this->actingAs($user)
            ->post(route('chats.feedback', [$chat, $message]), ['helpful' => false])
            ->assertOk()
            ->assertJson(['ok' => true, 'was_helpful' => false]);

        $this->assertFalse($message->fresh()->was_helpful);
    }

    public function test_the_browser_string_payload_is_accepted(): void
    {
        [$user, $chat, $message] = $this->assistantInOwnChat();

        $this->actingAs($user)
            ->post(route('chats.feedback', [$chat, $message]), ['helpful' => 'true'])
            ->assertOk()
            ->assertJson(['ok' => true, 'was_helpful' => true]);

        $this->actingAs($user)
            ->post(route('chats.feedback', [$chat, $message]), ['helpful' => 'false'])
            ->assertOk()
            ->assertJson(['ok' => true, 'was_helpful' => false]);

        $this->assertFalse($message->fresh()->was_helpful);
    }

    public function test_rating_again_without_a_value_clears_the_previous_vote(): void
    {
        [$user, $chat, $message] = $this->assistantInOwnChat();

        $this->actingAs($user)
            ->post(route('chats.feedback', [$chat, $message]), ['helpful' => true])
            ->assertOk();

        $this->actingAs($user)
            ->post(route('chats.feedback', [$chat, $message]))
            ->assertOk()
            ->assertJson(['ok' => true, 'was_helpful' => null]);

        $this->assertNull($message->fresh()->was_helpful);
    }

    public function test_a_user_message_cannot_be_rated(): void
    {
        $user = User::factory()->create();
        $chat = Chat::factory()->for($user)->create();
        $prompt = ChatMessage::factory()->create(['chat_id' => $chat->getKey()]);

        $this->actingAs($user)
            ->post(route('chats.feedback', [$chat, $prompt]), ['helpful' => true])
            ->assertNotFound();

        $this->assertNull($prompt->fresh()->was_helpful);
    }

    public function test_a_message_from_another_users_conversation_cannot_be_rated(): void
    {
        [$owner, $chat, $message] = $this->assistantInOwnChat();
        $intruder = User::factory()->create();

        $this->actingAs($intruder)
            ->post(route('chats.feedback', [$chat, $message]), ['helpful' => true])
            ->assertForbidden();

        $this->assertNull($message->fresh()->was_helpful);
    }

    public function test_a_stored_vote_is_rendered_as_the_active_thumb(): void
    {
        [$user, $chat, $message] = $this->assistantInOwnChat();

        $message->update(['was_helpful' => true]);

        $page = $this->actingAs($user)->get(route('chats.show', $chat));

        $page->assertOk();
        $page->assertSee('data-feedback-thumb="up"', false);
        $page->assertSee('data-state="true"', false);
        $page->assertSee('aria-pressed="true"', false);
    }

    /**
     * @return array{0: User, 1: Chat, 2: ChatMessage}
     */
    private function assistantInOwnChat(): array
    {
        $user = User::factory()->create();
        $chat = Chat::factory()->for($user)->create();
        $message = ChatMessage::factory()->assistant('Grounded answer.')->create([
            'chat_id' => $chat->getKey(),
        ]);

        $this->assertSame(ChatRole::Assistant, $message->role);

        return [$user, $chat, $message];
    }
}
