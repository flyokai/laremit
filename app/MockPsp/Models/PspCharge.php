<?php

declare(strict_types=1);

namespace App\MockPsp\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * The mock PSP's record of a charge — the provider side of the wire, kept
 * strictly out of App\Domain. Laremit's code must never read this table;
 * the tests that assert "exactly one charge happened" are standing in for
 * the provider's dashboard.
 *
 * @property int $id
 * @property string $charge_id
 * @property string $idempotency_key
 * @property string $request_hash
 * @property int $amount_minor
 * @property string $currency
 * @property string $status
 * @property string|null $decline_code
 * @property array<string, mixed> $metadata
 * @property int $response_status
 * @property array<string, mixed> $response_body
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable([
    'charge_id',
    'idempotency_key',
    'request_hash',
    'amount_minor',
    'currency',
    'status',
    'decline_code',
    'metadata',
    'response_status',
    'response_body',
])]
final class PspCharge extends Model
{
    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_FAILED = 'failed';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'metadata' => 'array',
            'response_status' => 'integer',
            'response_body' => 'array',
        ];
    }
}
