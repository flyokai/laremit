<?php

declare(strict_types=1);

namespace App\Domain\Billing\Reconciliation\Sweeps;

use App\Domain\Billing\Enums\Store;
use App\Domain\Billing\Enums\SubscriptionStatus;
use App\Domain\Billing\Exceptions\StoreUnavailable;
use App\Domain\Billing\Models\Subscription;
use App\Domain\Billing\Reconciliation\ReconciliationReport;
use App\Domain\Billing\Reconciliation\Sweep;
use App\Domain\Billing\Stores\StoreClient;
use App\Domain\Billing\Stores\StoreSubscriptionProjector;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

/**
 * Store -> us, for IAP (ADR-005). There is no "us -> store" direction
 * here in the PSP sense: the store cannot be wrong about a subscription
 * it owns, so the only question is whether our projection has drifted.
 * Every live store-backed row (plus any that changed inside the window)
 * is re-fetched and projected with the fetch time as its clock; a row
 * whose state moved is a fix, and one the store has no record of pages.
 *
 * The store being down is one finding, not one per row: the first
 * StoreUnavailable ends the sweep, and the next run picks it up.
 */
final readonly class StoreSubscriptionSweep implements Sweep
{
    public function __construct(
        private StoreClient $client,
        private StoreSubscriptionProjector $projector,
    ) {}

    public function sweep(ReconciliationReport $report, CarbonImmutable $now, CarbonImmutable $windowStart): void
    {
        $unavailable = false;

        Subscription::query()
            ->whereIn('store', [Store::Apple->value, Store::Google->value])
            ->where(function ($query) use ($windowStart): void {
                $query->whereNotIn('status', [SubscriptionStatus::Expired->value, SubscriptionStatus::Revoked->value])
                    ->orWhere('last_event_at', '>=', $windowStart);
            })
            ->chunkById(100, function (Collection $subscriptions) use ($report, $now, &$unavailable): bool {
                /** @var Subscription $subscription */
                foreach ($subscriptions as $subscription) {
                    $report->scanned('store_subscriptions');

                    $identifier = (string) $subscription->store_original_transaction_id;

                    try {
                        $snapshot = $this->client->fetchSubscription($subscription->store, $identifier, $now, 'RECONCILE');
                    } catch (StoreUnavailable $e) {
                        $report->unresolved('store_unavailable', ['store' => $subscription->store->value, 'reason' => $e->getMessage()]);
                        $unavailable = true;

                        return false;
                    }

                    if ($snapshot === null) {
                        $report->unresolved('orphan_store_subscription', ['subscription_id' => $subscription->id, 'store' => $subscription->store->value]);

                        continue;
                    }

                    $result = $this->projector->project($snapshot);

                    match ($result->verdict) {
                        'applied' => $report->fixed('store_drift', [
                            'subscription_id' => $subscription->id,
                            'store' => $subscription->store->value,
                            'was' => $subscription->status->value,
                            'now' => $result->subscription?->status->value,
                        ]),
                        'confirmed', 'stale' => null,
                        default => $report->unresolved("store_{$result->verdict}", ['subscription_id' => $subscription->id]),
                    };
                }

                return true;
            });
    }
}
