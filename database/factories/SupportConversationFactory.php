<?php

namespace Database\Factories;

use App\Models\SupportConversation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SupportConversation>
 */
class SupportConversationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'agent_id' => null,
            'chat_id' => null,
            'status' => 'open',
            'message_count' => 0,
            'last_message_at' => now(),
            'resolved_at' => null,
        ];
    }

    /**
     * A conversation a support agent already picked up.
     */
    public function assigned(): static
    {
        return $this->state(fn (array $attributes) => [
            'agent_id' => User::factory()->support(),
            'status' => 'assigned',
        ]);
    }

    /**
     * A conversation that has been closed out.
     */
    public function resolved(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'resolved',
            'resolved_at' => now(),
        ]);
    }
}
