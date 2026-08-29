<?php

declare(strict_types=1);

namespace App\MockStores\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * The pretend store's own subscription record — the source of truth
 * ADR-005 defers to, kept strictly out of App\Domain. Laremit never reads
 * this table; it asks the store's API, like it would the real one.
 *
 * @property int $id
 * @property string $store
 * @property string $identifier
 * @property string|null $linked_identifier
 * @property string $product_id
 * @property string|null $app_account_token
 * @property string $status
 * @property bool $auto_renew
 * @property CarbonImmutable $period_start
 * @property CarbonImmutable $period_end
 * @property int $period_days
 * @property string $environment
 * @property bool $acknowledged
 * @property CarbonImmutable $event_at
 * @property CarbonImmutable|null $revoked_at
 */
#[Fillable([
    'store',
    'identifier',
    'linked_identifier',
    'product_id',
    'app_account_token',
    'status',
    'auto_renew',
    'period_start',
    'period_end',
    'period_days',
    'environment',
    'acknowledged',
    'event_at',
    'revoked_at',
])]
final class StoreSubscription extends Model
{
    protected $table = 'mock_store_subscriptions';

    /** Millisecond precision: event_at is the store's version clock. */
    protected $dateFormat = 'Y-m-d H:i:s.v';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'auto_renew' => 'boolean',
            'acknowledged' => 'boolean',
            'period_days' => 'integer',
            'period_start' => 'immutable_datetime',
            'period_end' => 'immutable_datetime',
            'event_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }
}
