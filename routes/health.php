<?php

declare(strict_types=1);

use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

// No middleware at all, by design: probes must not start a session (which would
// write a session record to Redis every few seconds), must not be rate limited,
// and must not depend on any of the things they are checking.
Route::get('/health', HealthController::class)->name('health');
