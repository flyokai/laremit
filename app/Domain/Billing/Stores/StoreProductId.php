<?php

declare(strict_types=1);

namespace App\Domain\Billing\Stores;

/**
 * The store-side product identifier and its mapping onto our catalog:
 * "{prefix}{product-slug}.{plan-slug}", e.g. com.laremit.edtech.monthly.
 * Deterministic in both directions so the catalog needs no extra column
 * and a store notification can be resolved without a lookup table.
 */
final class StoreProductId
{
    public static function of(string $productSlug, string $planSlug): string
    {
        return self::prefix().$productSlug.'.'.$planSlug;
    }

    /**
     * @return array{product: string, plan: string}|null null when the id is not ours
     */
    public static function parse(string $storeProductId): ?array
    {
        $prefix = self::prefix();

        if (! str_starts_with($storeProductId, $prefix)) {
            return null;
        }

        $rest = substr($storeProductId, strlen($prefix));
        $dot = strrpos($rest, '.');

        if ($dot === false || $dot === 0 || $dot === strlen($rest) - 1) {
            return null;
        }

        return ['product' => substr($rest, 0, $dot), 'plan' => substr($rest, $dot + 1)];
    }

    private static function prefix(): string
    {
        return (string) config('billing.stores.product_id_prefix');
    }
}
