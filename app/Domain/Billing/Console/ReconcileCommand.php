<?php

declare(strict_types=1);

namespace App\Domain\Billing\Console;

use App\Domain\Billing\Reconciliation\Reconciler;
use Illuminate\Console\Command;

/**
 * Bidirectional reconciliation on demand (and hourly from the scheduler).
 * The Phase 4 deliverable ends by running this and reading the "fixed"
 * line: after dropping a fifth of all webhooks, one run converges state
 * and says exactly how many discrepancies that took.
 */
final class ReconcileCommand extends Command
{
    protected $signature = 'billing:reconcile {--window-hours= : Override the rolling window (default from config)}';

    protected $description = 'Reconcile intents, refunds and store subscriptions against the providers, in both directions';

    public function handle(Reconciler $reconciler): int
    {
        $window = $this->option('window-hours');

        $run = $reconciler->run(windowHours: is_numeric($window) ? (int) $window : null);

        $this->line(sprintf(
            '<info>Run #%d</info>  window from %s  took %d ms',
            $run->id,
            $run->window_start->toDateTimeString(),
            $run->duration_ms,
        ));

        $this->table(
            ['scanned', 'count'],
            array_map(static fn (string $what, int $count): array => [$what, $count], array_keys($run->scanned), $run->scanned),
        );

        if ($run->findings === []) {
            $this->info('No discrepancies.');

            return self::SUCCESS;
        }

        $this->table(
            ['finding', 'count'],
            array_map(static fn (string $what, int $count): array => [$what, $count], array_keys($run->findings), $run->findings),
        );

        $this->line(sprintf('Fixed: <info>%d</info>   Unresolved: <comment>%d</comment>', $run->fixed, $run->unresolved));

        if ($run->unresolved > 0) {
            $this->error('UNRESOLVED DISCREPANCIES — the providers and the books disagree; see the log for each.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
