<?php

declare(strict_types=1);

namespace App\Support\Health;

use Throwable;

/**
 * A single readiness probe against one dependency.
 *
 * Implementations do not catch, time, or format anything — HealthChecker does
 * all three — so a check is only ever the smallest useful round trip.
 */
interface Check
{
    /** Stable identifier used as the key in the health payload. */
    public function name(): string;

    /**
     * Exercise the dependency.
     *
     * @return array<string, scalar> detail worth surfacing in the payload
     *
     * @throws Throwable if the dependency is unusable
     */
    public function probe(): array;
}
