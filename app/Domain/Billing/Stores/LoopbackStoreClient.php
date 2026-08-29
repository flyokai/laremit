<?php

declare(strict_types=1);

namespace App\Domain\Billing\Stores;

use App\Domain\Billing\Enums\Store;
use App\Domain\Billing\Stores\Apple\AppleNotificationParser;
use App\Domain\Billing\Stores\Google\GooglePurchaseMapper;
use App\MockStores\Apple\MockAppStore;
use App\MockStores\Google\MockPlayStore;
use App\Support\Jws\Jws;
use Carbon\CarbonImmutable;

/**
 * The mock stores invoked in-process — the test suite's driver. The
 * signed Apple data still goes through the real verifier, so a broken
 * signature path fails here exactly as it would over the wire.
 */
final readonly class LoopbackStoreClient implements StoreClient
{
    public function __construct(
        private MockAppStore $appStore,
        private MockPlayStore $playStore,
        private AppleNotificationParser $apple,
    ) {}

    public function fetchSubscription(Store $store, string $identifier, CarbonImmutable $eventAt, ?string $notificationType = null): ?StoreSubscriptionSnapshot
    {
        if ($store === Store::Apple) {
            $signed = $this->appStore->signedSubscription($identifier);

            if ($signed === null) {
                return null;
            }

            $signedDate = Jws::peekPayload($signed['signedTransactionInfo'])['signedDate'] ?? null;

            return $this->apple->snapshotFromSignedInfo(
                $signed['signedTransactionInfo'],
                $signed['signedRenewalInfo'],
                $signed['environment'],
                is_int($signedDate) ? CarbonImmutable::createFromTimestampMs($signedDate, 'UTC') : $eventAt,
                $notificationType,
            );
        }

        if ($store === Store::Google) {
            $purchase = $this->playStore->subscriptionsV2Get($identifier);

            return $purchase === null ? null : GooglePurchaseMapper::snapshot($purchase, $identifier, $eventAt, $notificationType);
        }

        return null;
    }

    public function acknowledge(Store $store, string $identifier): void
    {
        if ($store === Store::Google) {
            $this->playStore->acknowledge($identifier);
        }
    }

    public function needsAcknowledgement(Store $store, string $identifier): bool
    {
        if ($store !== Store::Google) {
            return false;
        }

        $purchase = $this->playStore->subscriptionsV2Get($identifier);

        return $purchase !== null && GooglePurchaseMapper::acknowledgementPending($purchase);
    }
}
