<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Events\Support\Envelope;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Stand-in reaction job for pipeline tests — the reaction map ships empty
 * until billing wires real reactions in Phase 3 (tech-debt #9).
 */
final class FakeReactionJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public Envelope $envelope) {}

    public function handle(): void
    {
        // Nothing: the tests assert dispatch counts, not effects.
    }
}
