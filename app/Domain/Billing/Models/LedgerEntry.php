<?php

declare(strict_types=1);

namespace App\Domain\Billing\Models;

use App\Domain\Billing\Enums\LedgerAccount;
use App\Domain\Billing\Enums\LedgerEntryType;
use App\Domain\Billing\Money\Money;
use App\Domain\Billing\Money\MoneyCast;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * One line of one balanced ledger transaction. Append-only is enforced in
 * code as well as by convention: updating or deleting a ledger entry throws.
 * Corrections are new adjustment transactions, the way ledgers have worked
 * since paper.
 *
 * @property int $id
 * @property string $transaction_id
 * @property LedgerAccount $account
 * @property LedgerEntryType $entry_type
 * @property int $amount_minor
 * @property string $currency
 * @property Money $amount
 * @property string $reference_type
 * @property string $reference_id
 * @property string $idempotency_key
 * @property CarbonImmutable $occurred_at
 * @property CarbonImmutable $created_at
 */
final class LedgerEntry extends Model
{
    public $timestamps = false;

    protected static function booted(): void
    {
        $immutable = static function (): never {
            throw new LogicException('ledger_entries is append-only; write an adjustment transaction instead.');
        };

        self::updating($immutable);
        self::deleting($immutable);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'account' => LedgerAccount::class,
            'entry_type' => LedgerEntryType::class,
            'amount' => MoneyCast::class,
            'occurred_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
