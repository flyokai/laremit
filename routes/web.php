<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

// This is a backend-core: it has no UI of its own. The root is a service
// descriptor so that hitting the host answers "what is this, and where are its
// operational endpoints" rather than rendering a framework welcome page.
Route::get('/', fn (): array => [
    'service' => config('app.name'),
    'environment' => config('app.env'),
    'endpoints' => [
        'liveness' => url('/up'),
        'readiness' => url('/health'),
        'queues' => url('/horizon'),
    ],
]);
