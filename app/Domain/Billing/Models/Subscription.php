<?php

declare(strict_types=1);

namespace App\Domain\Billing\Models;

use App\Domain\Billing\Enums\Store;
use App\Domain\Billing\Enums\SubscriptionStatus;
use App\Domain\Catalog\Models\Plan;
use App\Domain\Catalog\Models\Product;
use App\Domain\Identity\Models\User;
use Carbon\CarbonImmutable;
use Database\Factories\Billing\SubscriptionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One user's standing entitlement to one product.
 *
 * @property int $id
 * @property int $user_id
 * @property int $product_id
 * @property int $plan_id
 * @property SubscriptionStatus $status
 * @property Store $store
 * @property string|null $store_original_transaction_id
 * @property CarbonImmutable|null $trial_ends_at
 * @property CarbonImmutable|null $current_period_start
 * @property CarbonImmutable|null $current_period_end
 * @property CarbonImmutable|null $canceled_at
 * @property CarbonImmutable|null $last_event_at
 * @property-read User $user
 * @property-read Product $product
 * @property-read Plan $plan
 */
#[Fillable([
    'user_id',
    'product_id',
    'plan_id',
    'status',
    'store',
    'store_original_transaction_id',
    'trial_ends_at',
    'current_period_start',
    'current_period_end',
    'canceled_at',
    'last_event_at',
])]
final class Subscription extends Model
{
    /** @use HasFactory<SubscriptionFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<Plan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * Whether the paid period is still running.
     *
     * Kept separate from SubscriptionStatus::grantsAccess() because a cancelled
     * subscription keeps access until its period ends. Phase 3 combines the two
     * into the single hasAccessTo(user, product) entitlement function.
     */
    public function withinCurrentPeriod(?CarbonImmutable $at = null): bool
    {
        return $this->current_period_end !== null
            && $this->current_period_end->isAfter($at ?? CarbonImmutable::now());
    }

    protected static function newFactory(): SubscriptionFactory
    {
        return SubscriptionFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SubscriptionStatus::class,
            'store' => Store::class,
            'trial_ends_at' => 'immutable_datetime',
            'current_period_start' => 'immutable_datetime',
            'current_period_end' => 'immutable_datetime',
            'canceled_at' => 'immutable_datetime',
            'last_event_at' => 'immutable_datetime',
        ];
    }
}
