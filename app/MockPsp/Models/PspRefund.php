<?php

declare(strict_types=1);

namespace App\MockPsp\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * The mock PSP's record of one refund — provider side of the wire, like
 * PspCharge. One row per refund: partial refunds accumulate as rows, never
 * as a mutated amount on the charge.
 *
 * @property int $id
 * @property string $refund_id
 * @property string $charge_id
 * @property int $amount_minor
 * @property string $currency
 * @property string|null $reason
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['refund_id', 'charge_id', 'amount_minor', 'currency', 'reason'])]
final class PspRefund extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
        ];
    }
}
