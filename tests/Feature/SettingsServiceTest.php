<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingsServiceTest extends TestCase
{
    use RefreshDatabase;

    private Settings $settings;

    protected function setUp(): void
    {
        parent::setUp();

        $this->settings = $this->app->make(Settings::class);
    }

    public function test_it_returns_the_default_when_a_key_is_missing(): void
    {
        $this->assertNull($this->settings->get('openai.api_key'));
        $this->assertSame('fallback', $this->settings->get('openai.api_key', 'fallback'));
        $this->assertSame(42, $this->settings->getInt('missing', 42));
        $this->assertFalse($this->settings->has('openai.api_key'));
    }

    public function test_it_round_trips_a_plain_setting(): void
    {
        $this->settings->set('brand.name', 'Acme Docs', 'branding');

        $this->assertSame('Acme Docs', $this->settings->getString('brand.name'));
        $this->assertTrue($this->settings->has('brand.name'));

        $stored = Setting::query()->where('key', 'brand.name')->firstOrFail();
        $this->assertSame('branding', $stored->group);
        $this->assertFalse($stored->is_secret);
    }

    public function test_secret_settings_are_encrypted_at_rest(): void
    {
        $this->settings->set('openai.api_key', 'sk-super-secret-value', 'openai', true);

        $this->assertSame('sk-super-secret-value', $this->settings->get('openai.api_key'));

        $raw = Setting::query()->where('key', 'openai.api_key')->firstOrFail()->getRawOriginal('value');

        $this->assertStringNotContainsString('sk-super-secret-value', (string) $raw);
    }

    public function test_updating_an_existing_key_overwrites_the_previous_value(): void
    {
        $this->settings->set('brand.name', 'First');
        $this->settings->set('brand.name', 'Second');

        $this->assertSame('Second', $this->settings->getString('brand.name'));
        $this->assertSame(1, Setting::query()->count());
    }
}
