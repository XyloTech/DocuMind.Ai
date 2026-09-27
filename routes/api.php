<?php

use App\Http\Controllers\Widget\WidgetChatController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public Widget API
|--------------------------------------------------------------------------
|
| Consumed by the embeddable chat bubble. These routes are session-less and
| open to any origin, so every request is authenticated by a public site key
| and bounded by that site's message quota.
|
*/

Route::prefix('widget/{siteKey}')->name('widget.')->group(function (): void {
    Route::get('/config', [WidgetChatController::class, 'config'])
        ->middleware('throttle:widget')
        ->name('config');

    Route::post('/conversations', [WidgetChatController::class, 'store'])
        ->middleware('throttle:widget')
        ->name('conversations.store');

    Route::post('/events', [WidgetChatController::class, 'events'])
        ->middleware('throttle:widget-events')
        ->name('events');

    Route::prefix('conversations/{conversation}')->name('conversations.')->group(function (): void {
        Route::get('/', [WidgetChatController::class, 'show'])
            ->middleware('throttle:widget')
            ->name('show');
        Route::post('/messages', [WidgetChatController::class, 'messages'])
            ->middleware('throttle:widget')
            ->name('messages');
        Route::post('/feedback', [WidgetChatController::class, 'feedback'])
            ->middleware('throttle:widget')
            ->name('feedback');
    });
});
