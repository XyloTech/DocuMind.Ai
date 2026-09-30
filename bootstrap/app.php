<?php

use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\EnsureWorkspaceContext;
use App\Services\Notifier;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Cloud Run terminates TLS in front of the container, so without this
        // every generated URL (assets, canonical links, redirects) would come
        // out as http:// and be blocked as mixed content.
        $middleware->trustProxies(at: '*');

        $middleware->alias([
            'active' => EnsureUserIsActive::class,
            'workspace' => EnsureWorkspaceContext::class,
        ]);

        // The Razorpay webhook carries no session cookie, so there is no CSRF
        // token to match; its HMAC signature over the raw body is the
        // authenticity check instead.
        $middleware->preventRequestForgery(except: [
            'webhooks/razorpay',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Provider failures are reported from a dozen call sites; watching
        // them here means a repeating outage still surfaces as one notice to
        // the platform admins even where no call site raises its own.
        $exceptions->reportable(function (Throwable $exception): void {
            Notifier::integrationError($exception);
        });
    })->create();
