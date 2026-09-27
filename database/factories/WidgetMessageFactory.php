<?php

namespace Database\Factories;

use App\Enums\ChatRole;
use App\Models\Site;
use App\Models\WidgetConversation;
use App\Models\WidgetMessage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WidgetMessage>
 */
class WidgetMessageFactory extends Factory
{
    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'widget_conversation_id' => WidgetConversation::factory(),
            'role' => ChatRole::User,
            'content' => fake()->sentence(),
            'sources' => null,
            'model_used' => null,
            'latency_ms' => null,
            'credit_cost' => 0,
            'was_refused' => false,
            'was_helpful' => null,
        ];
    }

    public function assistant(string $content, array $sources = []): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => ChatRole::Assistant,
            'content' => $content,
            'sources' => $sources === [] ? null : $sources,
            'model_used' => 'local',
        ]);
    }
}
