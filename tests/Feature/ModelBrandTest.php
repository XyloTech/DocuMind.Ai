<?php

namespace Tests\Feature;

use App\Enums\ChatRole;
use App\Enums\MessageStatus;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\Document;
use App\Models\User;
use App\Support\ModelBrand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ModelBrandTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_chat_shows_a_branded_name_instead_of_the_model_identifier(): void
    {
        $user = User::factory()->create();
        $document = Document::factory()->create(['user_id' => $user->getKey()]);
        $chat = Chat::factory()->create([
            'user_id' => $user->getKey(),
            'document_id' => $document->getKey(),
            'title' => 'Invoice rules',
        ]);

        ChatMessage::factory()->create([
            'chat_id' => $chat->getKey(),
            'role' => ChatRole::Assistant,
            'content' => 'Invoices are due in thirty days.',
            'status' => MessageStatus::Complete,
            'model_used' => 'Qwen/Qwen2.5-3B-Instruct-GGUF',
            'latency_ms' => 15800,
        ]);

        $page = $this->actingAs($user)->get(route('chats.show', $chat));

        $page->assertOk();
        $page->assertSee('SonicRock Pro · 15.8s');
        $page->assertDontSee('Qwen', false);
        $page->assertDontSee('GGUF', false);
        $page->assertDontSee('15800', false);
    }

    public function test_the_settings_panel_names_the_active_model_branded(): void
    {
        $user = User::factory()->create();
        $document = Document::factory()->create(['user_id' => $user->getKey()]);
        $chat = Chat::factory()->create([
            'user_id' => $user->getKey(),
            'document_id' => $document->getKey(),
        ]);

        $page = $this->actingAs($user)->get(route('chats.show', $chat));

        $page->assertOk();
        $page->assertSee(ModelBrand::active());
        $page->assertDontSee('Instruct-GGUF', false);
    }

    public function test_an_unmatched_identifier_falls_back_to_the_brand(): void
    {
        config(['ml.display_name' => 'SonicRock Pro']);

        $this->assertSame('SonicRock Pro', ModelBrand::name('some/repo-I-have-never-seen'));
        $this->assertSame('SonicRock Pro', ModelBrand::name(null));
        $this->assertSame('SonicRock Pro', ModelBrand::name('local'));
    }

    public function test_a_configured_alias_overrides_the_fallback(): void
    {
        config(['ml.model_aliases' => ['qwen2.5' => 'SonicRock Lite']]);

        $this->assertSame('SonicRock Lite', ModelBrand::name('Qwen/Qwen2.5-3B-Instruct-GGUF'));
        $this->assertSame('SonicRock Pro', ModelBrand::name('gpt-4o-mini'));
    }

    public function test_latency_is_rendered_in_seconds_not_milliseconds(): void
    {
        $this->assertSame('15.8s', ModelBrand::seconds(15800));
        $this->assertSame('0.4s', ModelBrand::seconds(400));
        $this->assertNull(ModelBrand::seconds(0));
        $this->assertNull(ModelBrand::seconds(null));

        $this->assertSame('SonicRock Pro · 15.8s', ModelBrand::label('Qwen/Qwen2.5-3B-Instruct-GGUF', 15800));
        $this->assertSame('SonicRock Pro', ModelBrand::label('local', null));
    }
}
