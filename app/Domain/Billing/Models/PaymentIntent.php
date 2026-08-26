<?php

declare(strict_types=1);

namespace App\Domain\Billing\Models;

use App\Domain\Billing\Enums\PaymentIntentStatus;
use App\Domain\Billing\Money\Money;
use App\Domain\Billing\Money\MoneyCast;
use App\Domain\Catalog\Models\Plan;
use App\Domain\Identity\Models\User;
use Carbon\CarbonImmutable;
use Database\Factories\Billing\PaymentIntentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One attempt-series to move one amount of money for one purpose. The
 * psp_idempotency_key is minted once at creation and reused for every
 * retry, so the PSP can collapse the series to at most one charge.
 *
 * @property int $id
 * @property int $user_id
 * @property int $subscription_id
 * @property int $plan_id
 * @property string $purpose
 * @property int $amount_minor
 * @property string $currency
 * @property Money $amount
 * @property PaymentIntentStatus $status
 * @property string $psp_idempotency_key
 * @property string|null $psp_reference
 * @property string|null $last_error
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read User $user
 * @property-read Subscription $subscription
 * @property-read Plan $plan
 */
#[Fillable([
    'user_id',
    'subscription_id',
    'plan_id',
    'purpose',
    'amount',
    'status',
    'psp_idempotency_key',
    'psp_reference',
    'last_error',
])]
final class PaymentIntent extends Model
{
    /** @use HasFactory<PaymentIntentFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Subscription, $this>
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /**
     * @return BelongsTo<Plan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    protected static function newFactory(): PaymentIntentFactory
    {
        return PaymentIntentFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => MoneyCast::class,
            'status' => PaymentIntentStatus::class,
        ];
    }
}
