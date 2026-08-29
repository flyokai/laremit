<?php

declare(strict_types=1);

namespace App\Domain\Billing\Stores;

use App\Domain\Billing\Enums\Store;
use App\Domain\Billing\Exceptions\StoreUnavailable;
use Carbon\CarbonImmutable;

/**
 * The read side of the app stores — App Store Server API and Play
 * Developer API behind one seam. This is the "re-fetch" in ADR-005: every
 * store path that needs the truth asks here instead of believing a
 * notification or a device.
 */
interface StoreClient
{
    /**
     * The store's current statement about one subscription, or null when
     * the store has no such record (a definitive answer, unlike a throw).
     *
     * @param  CarbonImmutable  $eventAt  the clock to stamp the snapshot with when the store's answer carries none of its own
     *
     * @throws StoreUnavailable
     */
    public function fetchSubscription(Store $store, string $identifier, CarbonImmutable $eventAt, ?string $notificationType = null): ?StoreSubscriptionSnapshot;

    /**
     * Google requires acknowledging a purchase within three days or it is
     * refunded; Apple has no equivalent (no-op). Idempotent on the store side.
     *
     * @throws StoreUnavailable
     */
    public function acknowledge(Store $store, string $identifier): void;

    /** Whether the store's record still needs acknowledging (Google only). */
    public function needsAcknowledgement(Store $store, string $identifier): bool;
}
