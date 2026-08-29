<?php

declare(strict_types=1);

namespace App\MockStores;

use App\Domain\Billing\Enums\Store;
use App\MockStores\Jobs\DeliverStoreNotification;
use Illuminate\Support\Facades\Log;

/**
 * The stores' hostile delivery policy, shared by both mocks: late,
 * possibly duplicated, possibly never. A dropped notification is not
 * queued at all — Laremit cannot tell it from a store that never sent
 * one, which is exactly the situation reconciliation exists for.
 */
final readonly class NotificationScheduler
{
    public function __construct(private MockStoresSettings $settings) {}

    public function schedule(Store $store, string $body, string $eventId): void
    {
        if (mt_rand() / mt_getrandmax() < $this->settings->dropRate()) {
            Log::info('Mock store dropped a notification on purpose.', [
                'store' => $store->value,
                'event_id' => $eventId,
            ]);

            return;
        }

        [$minDelay, $maxDelay] = $this->settings->delayRange();

        DeliverStoreNotification::dispatch($store, $body, $eventId)
            ->delay(random_int($minDelay, max($minDelay, $maxDelay)));

        if (mt_rand() / mt_getrandmax() < $this->settings->duplicateRate()) {
            DeliverStoreNotification::dispatch($store, $body, $eventId)
                ->delay(random_int($minDelay, max($minDelay, $maxDelay) + 2));
        }
    }
}
