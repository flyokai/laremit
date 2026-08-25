<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        // No /api prefix: the brief's wire contract is POST /v1/events.
        apiPrefix: '',
        commands: __DIR__.'/../routes/console.php',
        // Liveness: the PHP process can serve a request. Readiness — can it
        // reach MySQL and all three Redis instances — is /health.
        health: '/up',
        then: function (): void {
            Route::group([], base_path('routes/health.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('v1/*', 'health'),
        );
    })->create();
