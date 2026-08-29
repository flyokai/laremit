<?php

declare(strict_types=1);

namespace App\Domain\Billing\Stores;

use App\Domain\Billing\Enums\Store;
use App\Domain\Billing\Enums\SubscriptionStatus;
use Carbon\CarbonImmutable;

/**
 * A store's complete current statement about one subscription, already
 * translated into our status vocabulary. Every store path — Apple's signed
 * notification payload, Google's re-fetched purchase, the reconciliation
 * re-sync, the client's restore call — produces this one shape, so the
 * projector cannot care which road the truth arrived by. Absolute state,
 * never a delta: that is what makes dropping a stale one safe (ADR-005).
 */
final readonly class StoreSubscriptionSnapshot
{
    public function __construct(
        public Store $store,
        /** Apple originalTransactionId, Google purchaseToken. */
        public string $storeIdentifier,
        /** Google: the purchaseToken this one replaced on resubscribe. */
        public ?string $linkedIdentifier,
        /** Our user's app_account_token as the store echoes it; null when the purchase was never linked. */
        public ?string $appAccountToken,
        public string $storeProductId,
        public string $environment,
        public SubscriptionStatus $status,
        /** The store's own word for it, for logs and forensics. */
        public string $storeStatus,
        public CarbonImmutable $periodStart,
        public CarbonImmutable $periodEnd,
        public bool $autoRenew,
        /** The store's clock for this state; ordering is decided against it. */
        public CarbonImmutable $eventAt,
        public ?string $notificationType = null,
    ) {}

    public function withStatus(SubscriptionStatus $status, string $storeStatus): self
    {
        return $this->with(['status' => $status, 'storeStatus' => $storeStatus]);
    }

    public function withEventAt(CarbonImmutable $eventAt): self
    {
        return $this->with(['eventAt' => $eventAt]);
    }

    public function withAppAccountToken(?string $appAccountToken): self
    {
        return $this->with(['appAccountToken' => $appAccountToken]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function with(array $overrides): self
    {
        /** @var array<string, mixed> $arguments */
        $arguments = array_merge(get_object_vars($this), $overrides);

        return new self(...$arguments);
    }
}
