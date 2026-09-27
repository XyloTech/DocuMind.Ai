<?php

namespace App\Providers;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\Ai\ChatClient;
use App\Services\Ai\EmbeddingProvider;
use App\Services\Ai\FakeChatClient;
use App\Services\Ai\FakeEmbeddingProvider;
use App\Services\Ai\OpenAiChatClient;
use App\Services\Ai\OpenAiEmbeddingProvider;
use App\Services\Settings;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(Settings::class);

        $this->app->singleton(EmbeddingProvider::class, function ($app): EmbeddingProvider {
            return match ($this->aiDriver($app)) {
                'local' => new OpenAiEmbeddingProvider($app->make(Settings::class), $this->localOptions(
                    (string) config('ml.embedding_model'),
                )),
                'openai' => new OpenAiEmbeddingProvider($app->make(Settings::class)),
                default => new FakeEmbeddingProvider((int) config('rag.embedding_dimensions', 1536)),
            };
        });

        $this->app->singleton(ChatClient::class, function ($app): ChatClient {
            return match ($this->aiDriver($app)) {
                'local' => new OpenAiChatClient($app->make(Settings::class), $this->localOptions(
                    (string) config('ml.chat_model'),
                )),
                'openai' => new OpenAiChatClient($app->make(Settings::class)),
                default => new FakeChatClient,
            };
        });
    }

    /**
     * Point the OpenAI-compatible clients at our own Python model service.
     *
     * @return array{base_url: string, api_key: string, model: string, label: string, timeout: int}
     */
    private function localOptions(string $model): array
    {
        return [
            'base_url' => (string) config('ml.base_url'),
            'api_key' => (string) config('ml.api_key'),
            'model' => $model,
            'label' => 'local',
            'timeout' => (int) config('ml.timeout'),
        ];
    }

    /**
     * "local" when our own model service is enabled, otherwise "openai" when a
     * key is configured and "fake" as the last-resort offline driver.
     */
    private function aiDriver(Application $app): string
    {
        $savedDriver = $app->make(Settings::class)->getString('ai_driver', '');

        if (in_array($savedDriver, ['local', 'openai', 'fake'], true)) {
            return $savedDriver;
        }

        $driver = (string) config('rag.ai_driver', '');

        if ($driver !== '') {
            return $driver;
        }

        if ((bool) config('ml.enabled', true)) {
            return 'local';
        }

        $settings = $app->make(Settings::class);
        $key = $settings->getString('openai_api_key', '');

        if ($key === '') {
            $key = (string) config('openai.api_key', '');
        }

        return $key !== '' ? 'openai' : 'fake';
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::define('access-admin', fn (User $user): bool => $user->hasAdminAccess());
        Gate::define('manage-admin', fn (User $user): bool => $user->canManagePlatform());
        Gate::define('view-admin-accounts', fn (User $user): bool => $user->isAdmin() || $user->role === UserRole::Support);
        Gate::define('view-admin-support-data', fn (User $user): bool => $user->isAdmin() || $user->role === UserRole::Support);
        Gate::define('view-admin-audit', fn (User $user): bool => $user->isAdmin());

        RateLimiter::for('firebase-login', fn (Request $request): Limit => Limit::perMinute(10)->by('firebase-login:'.$request->ip()),
        );

        RateLimiter::for('uploads', function (Request $request): Limit {
            return Limit::perHour(60)->by('uploads:'.($request->user()?->getKey() ?? $request->ip()));
        });

        RateLimiter::for('chat', function (Request $request): Limit {
            return Limit::perMinute(20)->by('chat:'.($request->user()?->getKey() ?? $request->ip()));
        });

        // Public widget traffic: bounded per visitor IP *and* per site so one
        // noisy page cannot drain a customer's monthly quota in minutes.
        RateLimiter::for('widget', function (Request $request): Limit {
            $site = (string) $request->route('siteKey', 'unknown');

            return Limit::perMinute(20)->by('widget:'.$site.':'.$request->ip());
        });

        // Engagement beacons are tiny and frequent, so they get their own
        // budget: sharing the chat limiter would let a chatty page starve the
        // messages that actually matter.
        RateLimiter::for('widget-events', function (Request $request): Limit {
            $site = (string) $request->route('siteKey', 'unknown');

            return Limit::perMinute(60)->by('widget-events:'.$site.':'.$request->ip());
        });

        // The header bell polls every ~30s; 60/min keeps a stuck tab honest
        // without ever punishing a human opening the panel.
        RateLimiter::for('notifications', function (Request $request): Limit {
            return Limit::perMinute(60)->by('notifications:'.($request->user()?->getKey() ?? $request->ip()));
        });
    }
}
