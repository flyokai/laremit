<?php

declare(strict_types=1);

namespace App\Domain\Billing\Entitlements;

use App\Domain\Billing\Enums\SubscriptionStatus;
use App\Domain\Billing\Models\Subscription;
use App\Domain\Catalog\Models\Product;
use Carbon\CarbonImmutable;

/**
 * THE entitlement function. Every "can this user use this product?"
 * decision in every product flows through here, so the two access rules —
 * a status that grants access, or a cancelled subscription still inside its
 * paid period — exist in exactly one place (the split that
 * SubscriptionStatus::grantsAccess() and withinCurrentPeriod() were each
 * carrying half of since Phase 1).
 */
final readonly class Entitlements
{
    public function hasAccessTo(int $userId, string $productSlug, ?CarbonImmutable $at = null): bool
    {
        $productId = Product::query()->where('slug', $productSlug)->value('id');

        if ($productId === null) {
            return false;
        }

        $at ??= CarbonImmutable::now();

        $grantingStatuses = array_filter(
            SubscriptionStatus::cases(),
            static fn (SubscriptionStatus $status): bool => $status->grantsAccess(),
        );

        return Subscription::query()
            ->where('user_id', $userId)
            ->where('product_id', $productId)
            ->where(function ($query) use ($grantingStatuses, $at): void {
                $query->whereIn('status', array_map(
                    static fn (SubscriptionStatus $status): string => $status->value,
                    array_values($grantingStatuses),
                ))->orWhere(function ($query) use ($at): void {
                    $query->where('status', SubscriptionStatus::Canceled->value)
                        ->where('current_period_end', '>', $at);
                });
            })
            ->exists();
    }
}
