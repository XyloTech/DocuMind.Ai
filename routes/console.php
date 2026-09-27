<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Retention windows chosen in Privacy & data are enforced once a day.
Schedule::command('privacy:prune')->dailyAt('03:00');
Schedule::command('widget:prune-visitors')->dailyAt('03:15');
