<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Health\HealthChecker;
use Illuminate\Http\JsonResponse;

/**
 * Readiness. Liveness is Laravel's own /up route, registered in bootstrap/app.php.
 *
 * The split matters to the orchestrator: /up failing means kill and restart the
 * process, while /health failing means take this instance out of rotation but
 * leave it alone — restarting it will not bring MySQL back.
 */
final class HealthController
{
    public function __invoke(HealthChecker $checker): JsonResponse
    {
        $report = $checker->run();

        return response()
            ->json($report->toArray(), $report->healthy() ? 200 : 503)
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate');
    }
}
