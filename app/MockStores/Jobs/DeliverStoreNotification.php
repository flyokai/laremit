<?php

declare(strict_types=1);

namespace App\MockStores\Jobs;

use App\Domain\Billing\Enums\Store;
use App\MockStores\MockStoresSettings;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\Facades\Log;

/**
 * One notification delivery from a mock store to Laremit. Apple's
 * authenticity is inside the body (the JWS); Google's is the Pub/Sub
 * push verification token on the URL. No HMAC header — the stores don't
 * send one, and the app must not expect one.
 */
final class DeliverStoreNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [5, 15];

    public function __construct(
        public Store $store,
        public string $body,
        public string $eventId,
    ) {}

    public function handle(Http $http, MockStoresSettings $settings): void
    {
        $url = match ($this->store) {
            Store::Apple => $settings->appleNotificationUrl(),
            Store::Google => $settings->googleNotificationUrl().'?token='.urlencode($settings->googlePubsubToken()),
            Store::Psp => '',
        };

        $response = $http
            ->timeout(5)
            ->withBody($this->body, 'application/json')
            ->post($url);

        if (! $response->successful()) {
            Log::warning('Mock store notification delivery rejected.', [
                'store' => $this->store->value,
                'status' => $response->status(),
                'event_id' => $this->eventId,
            ]);

            $response->throw();
        }
    }
}
