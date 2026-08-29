<?php

declare(strict_types=1);

namespace App\Domain\Billing\Stores;

use App\Domain\Billing\Enums\SubscriptionStatus;
use App\Domain\Billing\Models\Subscription;
use App\Domain\Billing\Subscriptions\SubscriptionStateMachine;
use App\Domain\Catalog\Models\Plan;
use App\Domain\Catalog\Models\Product;
use App\Domain\Identity\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The store-authoritative funnel (ADR-005): every path that learns the
 * store's truth — signed notification, re-fetch after an RTDN, hourly
 * re-sync, the client's restore call — ends here, under the subscription
 * row lock, where one guard decides ordering: the snapshot's store clock
 * against the row's last_event_at. Older loses. Nothing else in the
 * system may write a store-backed subscription.
 *
 * Upsert, never update: a notification can be the first we hear of a
 * purchase (the SUBSCRIBED one was dropped, or DID_RENEW outran it), so
 * an unknown identifier creates the row — provided the store told us
 * whose it is.
 */
final readonly class StoreSubscriptionProjector
{
    public function __construct(private SubscriptionStateMachine $subscriptions) {}

    public function project(StoreSubscriptionSnapshot $snapshot): ProjectionResult
    {
        $expected = (string) config('billing.stores.environment');

        if (strcasecmp($snapshot->environment, $expected) !== 0) {
            Log::warning('Discarding store snapshot from the wrong environment.', [
                'store' => $snapshot->store->value,
                'identifier' => $snapshot->storeIdentifier,
                'environment' => $snapshot->environment,
                'expected' => $expected,
            ]);

            return new ProjectionResult('wrong_environment');
        }

        $plan = $this->resolvePlan($snapshot->storeProductId);

        if ($plan === null) {
            Log::error('Store snapshot names a product that is not in the catalog.', [
                'store' => $snapshot->store->value,
                'store_product_id' => $snapshot->storeProductId,
            ]);

            return new ProjectionResult('unknown_product');
        }

        return DB::transaction(function () use ($snapshot, $plan): ProjectionResult {
            $subscription = $this->lockRow($snapshot);

            if ($subscription === null) {
                $userId = $this->resolveUser($snapshot->appAccountToken);

                if ($userId === null) {
                    Log::warning('Store snapshot for an unknown purchase carries no account link; waiting for the client to restore it.', [
                        'store' => $snapshot->store->value,
                        'identifier' => $snapshot->storeIdentifier,
                    ]);

                    return new ProjectionResult('unknown_user');
                }

                $subscription = Subscription::query()->create([
                    'user_id' => $userId,
                    'product_id' => $plan->product_id,
                    'plan_id' => $plan->id,
                    'status' => SubscriptionStatus::Incomplete,
                    'store' => $snapshot->store,
                    'store_original_transaction_id' => $snapshot->storeIdentifier,
                ]);
            } elseif ($subscription->product_id !== $plan->product_id) {
                Log::error('Store snapshot names a different product than the subscription it identifies.', [
                    'subscription_id' => $subscription->id,
                    'store_product_id' => $snapshot->storeProductId,
                ]);

                return new ProjectionResult('product_mismatch', $subscription);
            }

            $status = $snapshot->status;

            // A revoked row that the store now merely calls expired is not
            // drift: revocation is our stronger word for the same absence.
            if ($subscription->status === SubscriptionStatus::Revoked && $status === SubscriptionStatus::Expired) {
                $status = SubscriptionStatus::Revoked;
            }

            // Period columns are second-precision; the stores' clocks are
            // milliseconds. Compare and write at the column's precision so
            // a re-sync of an unchanged record is "confirmed", not "applied".
            $periodStart = $snapshot->periodStart->floorSecond();
            $periodEnd = $snapshot->periodEnd->floorSecond();

            $changed = $subscription->status !== $status
                || $subscription->plan_id !== $plan->id
                || ! $this->sameInstant($subscription->current_period_end, $periodEnd)
                || ! $this->sameInstant($subscription->current_period_start, $periodStart);

            $written = $this->subscriptions->mirror($subscription, $status, $snapshot->eventAt, [
                'plan_id' => $plan->id,
                'current_period_start' => $periodStart,
                'current_period_end' => $periodEnd,
            ]);

            if (! $written) {
                return new ProjectionResult('stale', $subscription);
            }

            return new ProjectionResult($changed ? 'applied' : 'confirmed', $subscription);
        });
    }

    /**
     * Find the row by store identity; for Google, follow linkedPurchaseToken
     * so a resubscribe keeps the (user, product) row and just re-keys it.
     */
    private function lockRow(StoreSubscriptionSnapshot $snapshot): ?Subscription
    {
        $subscription = Subscription::query()
            ->where('store', $snapshot->store->value)
            ->where('store_original_transaction_id', $snapshot->storeIdentifier)
            ->lockForUpdate()
            ->first();

        if ($subscription !== null || $snapshot->linkedIdentifier === null) {
            return $subscription;
        }

        $linked = Subscription::query()
            ->where('store', $snapshot->store->value)
            ->where('store_original_transaction_id', $snapshot->linkedIdentifier)
            ->lockForUpdate()
            ->first();

        if ($linked === null) {
            return null;
        }

        // The old token is invalidated by the store the moment the new one
        // exists; nothing can ever arrive for it again.
        Subscription::query()->whereKey($linked->id)->update([
            'store_original_transaction_id' => $snapshot->storeIdentifier,
        ]);

        return $linked->refresh();
    }

    private function resolvePlan(string $storeProductId): ?Plan
    {
        $parsed = StoreProductId::parse($storeProductId);

        if ($parsed === null) {
            return null;
        }

        $productId = Product::query()->where('slug', $parsed['product'])->value('id');

        if ($productId === null) {
            return null;
        }

        return Plan::query()->where('product_id', $productId)->where('slug', $parsed['plan'])->first();
    }

    private function resolveUser(?string $appAccountToken): ?int
    {
        if ($appAccountToken === null) {
            return null;
        }

        $id = User::query()->where('app_account_token', $appAccountToken)->value('id');

        return is_int($id) ? $id : null;
    }

    private function sameInstant(?CarbonImmutable $a, CarbonImmutable $b): bool
    {
        return $a !== null && $a->equalTo($b);
    }
}
