<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API routes
|--------------------------------------------------------------------------
|
| Phase 2 mounts the event ingest API here:
|
|   POST /api/v1/events  — batch up to 500, gzip, envelope-only validation,
|                          202 with per-event status
|
| Deliberately empty for now rather than absent, so the routing is wired and
| the middleware group exists before there is anything to hang off it.
|
*/

Route::prefix('v1')->group(function (): void {
    //
});
