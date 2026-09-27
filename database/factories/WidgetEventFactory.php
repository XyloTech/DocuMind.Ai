<?php

namespace Database\Factories;

use App\Models\Site;
use App\Models\WidgetEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WidgetEvent>
 */
class WidgetEventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'type' => WidgetEvent::TYPES[0],
            'visitor_id_hash' => null,
            'meta' => null,
        ];
    }

    /**
     * A visitor who actually talked to the assistant.
     */
    public function identified(): static
    {
        return $this->state(fn (): array => [
            'visitor_id_hash' => hash('sha256', $this->faker->uuid()),
        ]);
    }
}
