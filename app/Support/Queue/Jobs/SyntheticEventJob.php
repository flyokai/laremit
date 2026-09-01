<?php

declare(strict_types=1);

namespace App\Support\Queue\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * The flood demo's payload (`queue:flood`, ADR-007): a job that does nothing,
 * a million times. Deliberately empty — the isolation claim under test is
 * about the queue machinery itself (pop, reserve, ack, Horizon metering),
 * and any real work in here would only muddy whose time was measured.
 *
 * Silenced in config/horizon.php so a million completions don't spend the
 * queue instance's memory on dashboard bookkeeping.
 */
final class SyntheticEventJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct()
    {
        $this->onConnection('events');
        $this->onQueue('events');
    }

    public function handle(): void
    {
        // Nothing, on purpose.
    }
}
