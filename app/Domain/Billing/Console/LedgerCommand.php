<?php

declare(strict_types=1);

namespace App\Domain\Billing\Console;

use App\Domain\Billing\Ledger\Ledger;
use Illuminate\Console\Command;

/**
 * Trial balance on demand: per-account balances and the zero-sum check.
 * "The ledger is provably correct" is this command exiting 0 — the chaos
 * runbook ends by running it.
 */
final class LedgerCommand extends Command
{
    protected $signature = 'billing:ledger';

    protected $description = 'Print the ledger trial balance and verify the zero-sum invariant';

    public function handle(Ledger $ledger): int
    {
        $balance = $ledger->trialBalance();

        $this->table(
            ['account', 'currency', 'balance (minor units)'],
            array_map(static fn (array $row): array => [
                $row['account'],
                $row['currency'],
                number_format($row['balance_minor']),
            ], $balance['accounts']),
        );

        $this->line(sprintf('%d entries; grand total %d.', $balance['entries'], $balance['total_minor']));

        if (! $balance['balanced']) {
            $this->error('LEDGER OUT OF BALANCE — the grand total must be exactly zero.');

            return self::FAILURE;
        }

        $this->info('Balanced: every transaction sums to zero.');

        return self::SUCCESS;
    }
}
