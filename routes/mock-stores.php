<?php

declare(strict_types=1);

use App\MockStores\Http\MockAppleController;
use App\MockStores\Http\MockGoogleController;
use App\MockStores\Http\MockStoresConfigController;
use Illuminate\Support\Facades\Route;

// The pretend App Store and Play Store (loaded only when mockstores.enabled
// — see bootstrap/app.php). Like /mock-psp, everything here is the other
// side of the wire: the console endpoints stand in for a user tapping
// "subscribe" on a phone, the API endpoints for the stores' server APIs.

Route::prefix('apple')->group(function (): void {
    Route::post('/purchases', [MockAppleController::class, 'purchase'])->name('mockstores.apple.purchase');
    Route::post('/subscriptions/{id}/{action}', [MockAppleController::class, 'act'])->name('mockstores.apple.act');
    Route::get('/inApps/v1/subscriptions/{id}', [MockAppleController::class, 'subscription'])->name('mockstores.apple.subscription');
});

Route::prefix('google')->group(function (): void {
    Route::post('/purchases', [MockGoogleController::class, 'purchase'])->name('mockstores.google.purchase');
    Route::post('/purchases/{token}/{action}', [MockGoogleController::class, 'act'])->name('mockstores.google.act');
    Route::get('/androidpublisher/v3/applications/{package}/purchases/subscriptionsv2/tokens/{token}', [MockGoogleController::class, 'subscription'])
        ->name('mockstores.google.subscription');
    Route::post('/androidpublisher/v3/applications/{package}/purchases/subscriptionsv2/tokens/{token}/acknowledge', [MockGoogleController::class, 'acknowledge'])
        ->name('mockstores.google.acknowledge');
});

Route::get('/config', [MockStoresConfigController::class, 'show'])->name('mockstores.config.show');
Route::post('/config', [MockStoresConfigController::class, 'configure'])->name('mockstores.config.update');
Route::delete('/config', [MockStoresConfigController::class, 'reset'])->name('mockstores.config.reset');
