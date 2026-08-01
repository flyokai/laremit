<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Models;

use Database\Factories\Catalog\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A sellable product sharing the backend-core: the EdTech app, the VPN, the
 * AI tutor. Entitlements are always answered per (user, product).
 *
 * @property int $id
 * @property string $slug
 * @property string $name
 * @property bool $active
 * @property-read Collection<int, Plan> $plans
 */
#[Fillable(['slug', 'name', 'active'])]
final class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    /**
     * @return HasMany<Plan, $this>
     */
    public function plans(): HasMany
    {
        return $this->hasMany(Plan::class);
    }

    protected static function newFactory(): ProductFactory
    {
        return ProductFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
    }
}
