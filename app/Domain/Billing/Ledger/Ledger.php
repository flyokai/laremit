<?php

declare(strict_types=1);

namespace App\Domain\Billing\Ledger;

use App\Domain\Billing\Enums\LedgerAccount;
use App\Domain\Billing\Enums\LedgerEntryType;
use App\Domain\Billing\Models\LedgerEntry;
use App\Domain\Billing\Models\PaymentIntent;
use App\Domain\Billing\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The append-only ledger. Two invariants carry everything:
 *
 *  1. Balanced: every transaction's lines sum to zero per currency,
 *     validated before a single row is written.
 *  2. Written once: each line carries a deterministic idempotency key
 *     derived from the business fact it records ("charge:ch_x:psp_cash").
 *     Re-recording the same fact — duplicate webhook, redelivered job,
 *     concurrent race that slipped every state guard — hits the unique
 *     constraint and reports "already recorded" instead of double-booking.
 *
 * All-or-nothing per transaction: the lines insert inside one database
 * transaction, so a partially-booked transfer cannot exist.
 */
final readonly class Ledger
{
    /**
     * Record a balanced transaction. Returns false when this exact scope was
     * already recorded (the caller's fact is already in the books).
     *
     * @param  list<array{LedgerAccount, Money}>  $lines
     */
    public function record(
        LedgerEntryType $type,
        string $referenceType,
        string $referenceId,
        string $idempotencyScope,
        CarbonImmutable $occurredAt,
        array $lines,
    ): bool {
        $this->assertBalanced($lines);

        $transactionId = (string) Str::ulid();
        $now = CarbonImmutable::now();

        $rows = [];

        foreach ($lines as [$account, $money]) {
            $rows[] = [
                'transaction_id' => $transactionId,
                'account' => $account->value,
                'entry_type' => $type->value,
                'amount_minor' => $money->minor,
                'currency' => $money->currency,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'idempotency_key' => "{$idempotencyScope}:{$account->value}",
                'occurred_at' => $occurredAt->format('Y-m-d H:i:s'),
                'created_at' => $now->format('Y-m-d H:i:s'),
            ];
        }

        try {
            // One statement inside the caller's (or its own) transaction:
            // either every line lands or none do, and a duplicate scope
            // rolls the whole batch back via the unique constraint.
            DB::transaction(function () use ($rows): void {
                DB::table('ledger_entries')->insert($rows);
            });
        } catch (QueryException $e) {
            if ($this->isUniqueViolation($e)) {
                return false;
            }

            throw $e;
        }

        return true;
    }

    /**
     * A successful charge: the PSP now holds our money (+psp_cash), earned
     * as revenue (credit-normal, so negative).
     */
    public function recordCharge(PaymentIntent $intent, string $chargeId, CarbonImmutable $occurredAt): bool
    {
        $amount = $intent->amount;

        if (! $amount->isPositive()) {
            throw new InvalidArgumentException("Refusing to record a non-positive charge ({$amount}).");
        }

        return $this->record(
            LedgerEntryType::Charge,
            'payment_intent',
            (string) $intent->id,
            "charge:{$chargeId}",
            $occurredAt,
            [
                [LedgerAccount::PspCash, $amount],
                [LedgerAccount::Revenue, $amount->negate()],
            ],
        );
    }

    public function balance(LedgerAccount $account, string $currency): Money
    {
        $sum = LedgerEntry::query()
            ->where('account', $account->value)
            ->where('currency', $currency)
            ->sum('amount_minor');

        return Money::of((int) $sum, $currency);
    }

    /**
     * Trial balance: per-account, per-currency sums plus the global zero
     * check. This is what "provably correct" prints.
     *
     * @return array{accounts: list<array{account: string, currency: string, balance_minor: int}>, total_minor: int, entries: int, balanced: bool}
     */
    public function trialBalance(): array
    {
        /** @var list<object{account: string, currency: string, balance_minor: string|int}> $rows */
        $rows = DB::table('ledger_entries')
            ->selectRaw('account, currency, SUM(amount_minor) as balance_minor')
            ->groupBy('account', 'currency')
            ->orderBy('account')
            ->get()
            ->all();

        $total = (int) DB::table('ledger_entries')->sum('amount_minor');

        return [
            'accounts' => array_map(static fn (object $row): array => [
                'account' => $row->account,
                'currency' => $row->currency,
                'balance_minor' => (int) $row->balance_minor,
            ], $rows),
            'total_minor' => $total,
            'entries' => (int) DB::table('ledger_entries')->count(),
            'balanced' => $total === 0,
        ];
    }

    /**
     * @param  list<array{LedgerAccount, Money}>  $lines
     */
    private function assertBalanced(array $lines): void
    {
        if (count($lines) < 2) {
            throw new InvalidArgumentException('A ledger transaction needs at least two lines.');
        }

        $perCurrency = [];

        foreach ($lines as [$account, $money]) {
            $perCurrency[$money->currency] = ($perCurrency[$money->currency] ?? 0) + $money->minor;
        }

        foreach ($perCurrency as $currency => $sum) {
            if ($sum !== 0) {
                throw new InvalidArgumentException(
                    "Unbalanced ledger transaction: {$currency} lines sum to {$sum}, not zero."
                );
            }
        }
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        // MySQL 1062; SQLite reports via message. Driver-portable enough for
        // the two drivers this project runs.
        return str_contains($e->getMessage(), 'Duplicate entry')
            || str_contains($e->getMessage(), 'UNIQUE constraint failed');
    }
}
