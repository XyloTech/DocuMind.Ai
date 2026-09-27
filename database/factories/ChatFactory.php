<?php

namespace Database\Factories;

use App\Models\Chat;
use App\Models\Document;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Chat>
 */
class ChatFactory extends Factory
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
            'document_id' => Document::factory(),
            'title' => fake()->sentence(3),
            'message_count' => 0,
            'last_message_at' => now(),
        ];
    }

    /**
     * A chat whose document has been detached by a deletion.
     */
    public function orphaned(): static
    {
        return $this->state(fn (array $attributes) => [
            'document_id' => null,
        ]);
    }
}
