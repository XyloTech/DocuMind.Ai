<?php

namespace Database\Factories;

use App\Models\Site;
use App\Models\WidgetConversation;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<WidgetConversation>
 */
class WidgetConversationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'visitor_id' => (string) Str::uuid(),
            'visitor_email' => null,
            'message_count' => 0,
        ];
    }
}
