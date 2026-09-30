<?php

namespace Database\Factories;

use App\Models\SupportConversation;
use App\Models\SupportMessage;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SupportMessage>
 */
class SupportMessageFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'support_conversation_id' => SupportConversation::factory(),
            'sender_id' => User::factory(),
            'role' => 'user',
            'content' => fake()->sentence(10),
            'read_at' => null,
        ];
    }

    /**
     * A reply typed by the assigned agent.
     */
    public function agent(string $content): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => 'agent',
            'content' => $content,
        ]);
    }

    /**
     * An automated note written by the platform rather than a person.
     */
    public function system(string $content): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => 'system',
            'sender_id' => null,
            'content' => $content,
        ]);
    }
}
