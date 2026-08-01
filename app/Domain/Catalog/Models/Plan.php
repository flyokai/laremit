<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Models;

use App\Domain\Catalog\Enums\BillingInterval;
use Database\Factories\Catalog\PlanFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Price point for a product.
 *
 * Amounts are integer minor units plus an ISO-4217 currency — never floats.
 * Phase 3 replaces the raw (amount_minor, currency) pair with a Money value
 * object and a MoneyCast; the column shape is already correct for that.
 *
 * @property int $id
 * @property int $product_id
 * @property string $slug
 * @property string $name
 * @property int $amount_minor
 * @property string $currency
 * @property BillingInterval $interval
 * @property int $interval_count
 * @property int $trial_days
 * @property bool $active
 * @property-read Product $product
 */
#[Fillable([
    'product_id',
    'slug',
    'name',
    'amount_minor',
    'currency',
    'interval',
    'interval_count',
    'trial_days',
    'active',
])]
final class Plan extends Model
{
    /** @use HasFactory<PlanFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    protected static function newFactory(): PlanFactory
    {
        return PlanFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'interval' => BillingInterval::class,
            'interval_count' => 'integer',
            'trial_days' => 'integer',
            'active' => 'boolean',
        ];
    }
}
