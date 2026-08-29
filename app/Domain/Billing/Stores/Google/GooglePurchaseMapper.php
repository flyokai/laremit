<?php

declare(strict_types=1);

namespace App\Domain\Billing\Stores\Google;

use App\Domain\Billing\Enums\Store;
use App\Domain\Billing\Enums\SubscriptionStatus;
use App\Domain\Billing\Stores\StoreSubscriptionSnapshot;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Play Developer API `purchases.subscriptionsv2` resource ->
 * StoreSubscriptionSnapshot. This is the only place Google's state
 * vocabulary is translated, so the mapping table below IS the policy.
 *
 * ON_HOLD and PAUSED both become our Paused: for entitlement purposes
 * they are identical (no access, not over, may come back). The store's
 * own word is kept alongside for anyone who needs the distinction.
 */
final class GooglePurchaseMapper
{
    private const STATES = [
        'SUBSCRIPTION_STATE_ACTIVE' => SubscriptionStatus::Active,
        'SUBSCRIPTION_STATE_CANCELED' => SubscriptionStatus::Canceled,
        'SUBSCRIPTION_STATE_IN_GRACE_PERIOD' => SubscriptionStatus::PastDue,
        'SUBSCRIPTION_STATE_ON_HOLD' => SubscriptionStatus::Paused,
        'SUBSCRIPTION_STATE_PAUSED' => SubscriptionStatus::Paused,
        'SUBSCRIPTION_STATE_EXPIRED' => SubscriptionStatus::Expired,
        'SUBSCRIPTION_STATE_PENDING' => SubscriptionStatus::Incomplete,
    ];

    /**
     * @param  array<string, mixed>  $purchase
     *
     * @throws InvalidArgumentException
     */
    public static function snapshot(
        array $purchase,
        string $purchaseToken,
        CarbonImmutable $eventAt,
        ?string $notificationType = null,
    ): StoreSubscriptionSnapshot {
        $state = $purchase['subscriptionState'] ?? null;
        $startTime = $purchase['startTime'] ?? null;
        $lineItems = $purchase['lineItems'] ?? null;

        if (! is_string($state) || ! isset(self::STATES[$state]) || ! is_string($startTime) || ! is_array($lineItems) || ! is_array($lineItems[0] ?? null)) {
            throw new InvalidArgumentException('SubscriptionPurchaseV2 is missing subscriptionState, startTime or lineItems.');
        }

        $line = $lineItems[0];
        $productId = $line['productId'] ?? null;
        $expiryTime = $line['expiryTime'] ?? null;

        if (! is_string($productId) || ! is_string($expiryTime)) {
            throw new InvalidArgumentException('SubscriptionPurchaseV2 line item is missing productId or expiryTime.');
        }

        $plan = is_array($line['autoRenewingPlan'] ?? null) ? $line['autoRenewingPlan'] : [];
        $identifiers = is_array($purchase['externalAccountIdentifiers'] ?? null) ? $purchase['externalAccountIdentifiers'] : [];
        $accountId = $identifiers['obfuscatedExternalAccountId'] ?? null;
        $linked = $purchase['linkedPurchaseToken'] ?? null;

        return new StoreSubscriptionSnapshot(
            store: Store::Google,
            storeIdentifier: $purchaseToken,
            linkedIdentifier: is_string($linked) && $linked !== '' ? $linked : null,
            appAccountToken: is_string($accountId) && $accountId !== '' ? $accountId : null,
            storeProductId: $productId,
            // Google has no environment field; license-tester purchases
            // carry a testPurchase marker instead.
            environment: isset($purchase['testPurchase']) ? 'Sandbox' : 'Production',
            status: self::STATES[$state],
            storeStatus: $state,
            periodStart: CarbonImmutable::parse($startTime)->utc(),
            periodEnd: CarbonImmutable::parse($expiryTime)->utc(),
            autoRenew: ($plan['autoRenewEnabled'] ?? false) === true,
            eventAt: $eventAt,
            notificationType: $notificationType,
        );
    }

    /**
     * @param  array<string, mixed>  $purchase
     */
    public static function acknowledgementPending(array $purchase): bool
    {
        return ($purchase['acknowledgementState'] ?? null) === 'ACKNOWLEDGEMENT_STATE_PENDING';
    }
}
