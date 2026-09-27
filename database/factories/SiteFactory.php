<?php

namespace Database\Factories;

use App\Enums\WidgetPosition;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Site>
 */
class SiteFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->company().' site',
            'site_key' => 'pk_'.Str::lower((string) Str::random(24)),
            'domain' => fake()->domainName(),
            'enabled' => true,
            'bot_name' => 'Assistant',
            'greeting' => null,
            'accent_color' => '#4f46e5',
            'logo_url' => null,
            'theme' => 'dark',
            'launcher_icon' => 'brand',
            'position' => WidgetPosition::BottomLeft,
            'collect_email' => false,
            'monthly_quota' => 1000,
            'messages_used' => 0,
            'quota_period' => 'month',
            'quota_started_at' => now()->startOfMonth(),
        ];
    }

    public function disabled(): static
    {
        return $this->state(fn (array $attributes) => [
            'enabled' => false,
        ]);
    }

    public function exhausted(): static
    {
        return $this->state(fn (array $attributes) => [
            'monthly_quota' => 0,
        ]);
    }
}
