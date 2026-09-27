<?php

namespace Database\Factories;

use App\Enums\ChatRole;
use App\Enums\MessageStatus;
use App\Models\Chat;
use App\Models\ChatMessage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChatMessage>
 */
class ChatMessageFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'chat_id' => Chat::factory(),
            'role' => ChatRole::User,
            'content' => fake()->sentence(),
            'credits_cost' => 0,
            'status' => MessageStatus::Complete,
            'sources' => null,
        ];
    }

    public function assistant(string $content, array $sources = []): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => ChatRole::Assistant,
            'content' => $content,
            'sources' => $sources === [] ? null : $sources,
            'model_used' => 'fake',
        ]);
    }

    public function streaming(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => ChatRole::Assistant,
            'content' => '',
            'status' => MessageStatus::Streaming,
        ]);
    }
}
