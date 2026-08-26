<?php

declare(strict_types=1);

use App\Http\Controllers\EntitlementController;
use App\Http\Controllers\IngestController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PspWebhookController;
use App\Http\Middleware\AuthenticateIngest;
use App\Http\Middleware\AuthenticateStaticToken;
use App\Http\Middleware\DecodesGzipRequests;
use App\Http\Middleware\EnforceIdempotency;
use Illuminate\Support\Facades\Route;

// Mounted with no /api prefix (bootstrap/app.php) so the wire path is
// exactly POST /v1/events. Auth runs before gzip decoding: nothing gets
// decompressed for a caller who could not present the token.
Route::prefix('v1')->group(function (): void {
    Route::post('/events', IngestController::class)
        ->middleware([AuthenticateIngest::class, DecodesGzipRequests::class])
        ->name('events.ingest');

    Route::middleware(AuthenticateStaticToken::class.':billing.api_token')->group(function (): void {
        Route::post('/payments', [PaymentController::class, 'store'])
            ->middleware(EnforceIdempotency::class)
            ->name('payments.store');
        Route::get('/payments/{payment}', [PaymentController::class, 'show'])
            ->whereNumber('payment')
            ->name('payments.show');
        Route::get('/entitlements', EntitlementController::class)->name('entitlements.show');
    });

    // Authenticated by HMAC signature, not bearer token; never idempotency-
    // middleware'd — webhook dedup happens at application, not at the edge.
    Route::post('/psp/webhook', PspWebhookController::class)->name('psp.webhook');
});
