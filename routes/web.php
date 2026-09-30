<?php

use App\Http\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Admin\DocumentController as AdminDocumentController;
use App\Http\Controllers\Admin\SupportController as AdminSupportController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\FirebaseSessionController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PrivacyController;
use App\Http\Controllers\SupportController;
use App\Http\Controllers\Widget\SiteController;
use App\Http\Controllers\Widget\WidgetAnalyticsController;
use App\Http\Controllers\Widget\WidgetLeadsController;
use App\Http\Controllers\Widget\WidgetScriptController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return Auth::check()
        ? redirect()->route('dashboard')
        : view('landing');
})->name('home');

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/auth/firebase', [FirebaseSessionController::class, 'store'])
        ->middleware('throttle:firebase-login')
        ->name('login.firebase');
});

// The embeddable widget bundle: a stable, cacheable URL for customers to
// paste into their own sites, so it lives outside the authenticated area.
Route::get('/widget.js', WidgetScriptController::class)->name('widget.script');

// Razorpay calls this from its own servers: no session, no CSRF token, so it
// sits with the public routes and authenticates on the body signature alone.
Route::post('/webhooks/razorpay', [BillingController::class, 'webhook'])
    ->middleware('throttle:razorpay-webhook')
    ->name('razorpay.webhook');

// The privacy notice is readable before an account exists, so it sits with the
// other public routes rather than behind auth.
Route::get('/privacy-policy', [PrivacyController::class, 'policy'])->name('privacy.policy');

Route::middleware(['auth', 'active', 'workspace'])->group(function (): void {
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    Route::prefix('billing')->name('billing.')->group(function (): void {
        Route::get('/', [BillingController::class, 'index'])->name('index');
        Route::post('/checkout', [BillingController::class, 'checkout'])
            ->middleware('throttle:billing')
            ->name('checkout');
        Route::post('/verify', [BillingController::class, 'verify'])
            ->middleware('throttle:billing')
            ->name('verify');
    });

    Route::post('/documents', [DocumentController::class, 'store'])
        ->middleware('throttle:uploads')
        ->name('documents.store');
    Route::get('/documents/{document}/status', [DocumentController::class, 'status'])->name('documents.status');
    Route::post('/documents/{document}/retry', [DocumentController::class, 'retry'])
        ->middleware('throttle:uploads')
        ->name('documents.retry');
    Route::get('/documents/{document}/suggestions', [ChatController::class, 'suggestions'])->name('documents.suggestions');
    Route::post('/documents/{document}/summarize', [ChatController::class, 'summarize'])
        ->middleware('throttle:chat')
        ->name('documents.summarize');
    Route::delete('/documents/{document}', [DocumentController::class, 'destroy'])->name('documents.destroy');

    Route::get('/chats', [ChatController::class, 'index'])->name('chats.index');
    Route::post('/chats', [ChatController::class, 'store'])->name('chats.store');
    Route::get('/chats/{chat}', [ChatController::class, 'show'])->name('chats.show');
    Route::patch('/chats/{chat}', [ChatController::class, 'update'])->name('chats.update');
    Route::delete('/chats/{chat}', [ChatController::class, 'destroy'])->name('chats.destroy');
    Route::post('/chats/{chat}/messages', [ChatController::class, 'messages'])
        ->middleware('throttle:chat')
        ->name('chats.messages');
    Route::post('/chats/{chat}/messages/{message}/feedback', [ChatController::class, 'feedback'])
        ->name('chats.feedback');

    Route::prefix('settings')->name('settings.')->group(function (): void {
        Route::get('/privacy', [PrivacyController::class, 'index'])->name('privacy');
        Route::patch('/privacy', [PrivacyController::class, 'update'])->name('privacy.update');
        Route::post('/privacy/consent', [PrivacyController::class, 'consent'])->name('privacy.consent');
        Route::post('/privacy/revoke', [PrivacyController::class, 'revoke'])->name('privacy.revoke');
        Route::get('/privacy/export', [PrivacyController::class, 'export'])->name('privacy.export');
        Route::delete('/privacy/data', [PrivacyController::class, 'destroy'])->name('privacy.destroy');
        Route::get('/notifications', [NotificationController::class, 'preferences'])->name('notifications');
        Route::patch('/notifications', [NotificationController::class, 'updatePreferences'])->name('notifications.update');
    });

    Route::prefix('notifications')->name('notifications.')->middleware('throttle:notifications')->group(function (): void {
        Route::get('/', [NotificationController::class, 'index'])->name('index');
        Route::get('/feed', [NotificationController::class, 'feed'])->name('feed');
        Route::get('/poll', [NotificationController::class, 'poll'])->name('poll');
        Route::get('/unread', [NotificationController::class, 'unreadCount'])->name('unread');
        Route::post('/read-all', [NotificationController::class, 'readAll'])->name('read-all');
        Route::post('/{notification}/read', [NotificationController::class, 'markRead'])->name('read');
    });

    // One-to-one channel with the support team: opened from the dashboard
    // chat when the assistant detects a handoff request, or directly here.
    Route::prefix('support')->name('support.')->group(function (): void {
        Route::get('/', [SupportController::class, 'index'])->name('index');
        Route::post('/messages', [SupportController::class, 'store'])
            ->middleware('throttle:chat')
            ->name('messages');
        Route::get('/poll', [SupportController::class, 'poll'])
            ->middleware('throttle:notifications')
            ->name('poll');
        Route::post('/close', [SupportController::class, 'close'])->name('close');
    });

    Route::prefix('widget')->name('widget.')->group(function (): void {
        Route::get('/', [SiteController::class, 'index'])->name('index');
        Route::post('/', [SiteController::class, 'store'])->name('store');
        Route::get('/{site}', [SiteController::class, 'show'])->name('show');
        Route::get('/{site}/preview', [SiteController::class, 'preview'])->name('preview');
        Route::put('/{site}', [SiteController::class, 'update'])->name('update');
        Route::delete('/{site}', [SiteController::class, 'destroy'])->name('destroy');
        Route::post('/{site}/rotate-key', [SiteController::class, 'rotateKey'])->name('rotate-key');
        Route::post('/{site}/toggle', [SiteController::class, 'toggle'])->name('toggle');
        Route::get('/{site}/analytics', WidgetAnalyticsController::class)->name('analytics');
        Route::get('/{site}/analytics/export', [WidgetAnalyticsController::class, 'export'])->name('analytics.export');
        Route::get('/{site}/leads', [WidgetLeadsController::class, 'index'])->name('leads.index');
        Route::get('/{site}/leads/export', [WidgetLeadsController::class, 'export'])->name('leads.export');
        Route::patch('/{site}/leads/{conversation}', [WidgetLeadsController::class, 'update'])->name('leads.update');
        Route::post('/{site}/leads/{conversation}/escalate', [WidgetLeadsController::class, 'escalate'])->name('leads.escalate');
        Route::post('/{site}/leads/{conversation}/resolve', [WidgetLeadsController::class, 'resolve'])->name('leads.resolve');
        Route::delete('/{site}/leads/{conversation}', [WidgetLeadsController::class, 'destroy'])->name('leads.destroy');
    });

    Route::prefix('admin')->name('admin.')->middleware('can:access-admin')->group(function (): void {
        Route::get('/', AdminDashboardController::class)->name('dashboard');
        Route::post('/users', [AdminDashboardController::class, 'storeUser'])
            ->middleware('can:manage-admin')
            ->name('users.store');
        Route::put('/users/{user}', [AdminDashboardController::class, 'updateUser'])
            ->middleware('can:manage-admin')
            ->name('users.update');
        Route::get('/users/export', [AdminDashboardController::class, 'exportUsers'])
            ->middleware('can:manage-admin')
            ->name('users.export');
        Route::post('/model-settings', [AdminDashboardController::class, 'updateModelSettings'])
            ->middleware('can:manage-admin')
            ->name('model-settings.update');

        Route::prefix('support')->name('support.')->middleware('can:view-admin-support-data')->group(function (): void {
            Route::get('/{supportConversation}', [AdminSupportController::class, 'show'])->name('show');
            Route::post('/{supportConversation}/reply', [AdminSupportController::class, 'reply'])->name('reply');
            Route::post('/{supportConversation}/resolve', [AdminSupportController::class, 'resolve'])->name('resolve');
        });

        Route::prefix('documents')->name('documents.')->group(function (): void {
            Route::get('/{document}', [AdminDocumentController::class, 'show'])
                ->middleware('can:view-admin-support-data')
                ->name('show');
            Route::patch('/{document}', [AdminDocumentController::class, 'update'])
                ->middleware('can:manage-admin')
                ->name('update');
            Route::delete('/{document}', [AdminDocumentController::class, 'destroy'])
                ->middleware('can:manage-admin')
                ->name('destroy');
        });
    });
});
