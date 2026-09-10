<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // The broadcast screen may run remotely / in a separate browser (vMix
        // web input) with no session cookie, so it cannot carry a CSRF token.
        // These endpoints are authorised by StudioBroadcastAccess (admin OR the
        // shared access token) instead — the token-bearer model does not need
        // CSRF. Every OTHER route keeps CSRF protection.
        $middleware->validateCsrfTokens(except: [
            'studio/live/session',
            'studio/live/state',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
