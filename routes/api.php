<?php

declare(strict_types=1);

use App\Http\Controllers\IngestController;
use App\Http\Middleware\AuthenticateIngest;
use App\Http\Middleware\DecodesGzipRequests;
use Illuminate\Support\Facades\Route;

// Mounted with no /api prefix (bootstrap/app.php) so the wire path is
// exactly POST /v1/events. Auth runs before gzip decoding: nothing gets
// decompressed for a caller who could not present the token.
Route::prefix('v1')->group(function (): void {
    Route::post('/events', IngestController::class)
        ->middleware([AuthenticateIngest::class, DecodesGzipRequests::class])
        ->name('events.ingest');
});
