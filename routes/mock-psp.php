<?php

declare(strict_types=1);

use App\MockPsp\Http\MockPspController;
use Illuminate\Support\Facades\Route;

// The pretend payment provider (loaded only when mockpsp.enabled — see
// bootstrap/app.php). Everything under /mock-psp is "the other side of the
// wire" and must never be treated as part of Laremit's own API surface.
Route::post('/v1/charges', [MockPspController::class, 'charge'])->name('mockpsp.charge');
Route::get('/v1/charges', [MockPspController::class, 'index'])->name('mockpsp.charges.index');
Route::post('/v1/charges/{charge}/refunds', [MockPspController::class, 'refund'])->name('mockpsp.refund');

Route::get('/config', [MockPspController::class, 'currentSettings'])->name('mockpsp.config.show');
Route::post('/config', [MockPspController::class, 'configure'])->name('mockpsp.config.update');
Route::delete('/config', [MockPspController::class, 'reset'])->name('mockpsp.config.reset');
