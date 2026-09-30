<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Retention windows chosen in Privacy & data are enforced once a day.
Schedule::command('privacy:prune')->dailyAt('03:00');
Schedule::command('widget:prune-visitors')->dailyAt('03:15');

// The local model service drops its weights when nothing pings it, and a cold
// instance would make the user's first message pay for loading the GGUF. One
// quiet GET a minute keeps it warm; a down service is already visible in the
// admin panel, so failures are swallowed rather than logged every 60 seconds.
Schedule::call(function (): void {
    $driver = (string) config('rag.ai_driver', '');
    $enabled = (bool) config('ml.enabled', true);

    if (! $enabled || $driver === 'openai' || $driver === 'fake') {
        return;
    }

    $url = (string) config('ml.health_url');

    if ($url === '') {
        return;
    }

    try {
        Http::connectTimeout(2)->timeout(5)->get($url);
    } catch (Throwable) {
        // Best-effort warm-up only.
    }
})->everyMinute()->name('ml:warm-up');
