<?php

declare(strict_types=1);

namespace App\Domain\Billing\Reconciliation;

use Illuminate\Support\Facades\Log;

/**
 * The tally one run builds up. Three kinds of finding, kept apart because
 * they mean different things on a dashboard:
 *
 *   fixed       we disagreed with the provider and the provider was
 *               right; local state now matches. Each one is a webhook
 *               that did not do its job — a rising rate is the signal.
 *   unresolved  we disagree and cannot safely fix it (money that moved
 *               for an intent we do not know, a settled intent whose
 *               outcome the provider contradicts). These page.
 *   noted       an action taken whose result is not known yet (a charge
 *               re-dispatched, a pending webhook re-queued).
 */
final class ReconciliationReport
{
    /** @var array<string, int> */
    public array $scanned = [];

    /** @var array<string, int> */
    public array $findings = [];

    public int $fixed = 0;

    public int $unresolved = 0;

    public function scanned(string $what, int $count = 1): void
    {
        $this->scanned[$what] = ($this->scanned[$what] ?? 0) + $count;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function fixed(string $finding, array $context = []): void
    {
        $this->tally($finding);
        $this->fixed++;

        Log::warning("Reconciliation fixed a discrepancy: {$finding}.", $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function unresolved(string $finding, array $context = []): void
    {
        $this->tally($finding);
        $this->unresolved++;

        Log::error("Reconciliation found a discrepancy it cannot fix: {$finding}.", $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function noted(string $finding, array $context = []): void
    {
        $this->tally($finding);

        Log::info("Reconciliation acted: {$finding}.", $context);
    }

    public function count(string $finding): int
    {
        return $this->findings[$finding] ?? 0;
    }

    private function tally(string $finding): void
    {
        $this->findings[$finding] = ($this->findings[$finding] ?? 0) + 1;
    }
}
