<?php

declare(strict_types=1);

namespace App\Domain\Billing\Reconciliation;

use Carbon\CarbonImmutable;

/** One direction of one comparison. The Reconciler runs them in order. */
interface Sweep
{
    public function sweep(ReconciliationReport $report, CarbonImmutable $now, CarbonImmutable $windowStart): void;
}
