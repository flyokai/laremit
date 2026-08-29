<?php

declare(strict_types=1);

namespace App\MockPsp\Jobs;

use App\Domain\Billing\Webhooks\WebhookSignature;
use App\MockPsp\MockPspSettings;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\Facades\Log;

/**
 * One webhook delivery from the mock PSP to Laremit. The signature is an
 * HMAC over the timestamp and the exact raw body, computed at send time —
 * which is why the body is serialized once here and sent with withBody(),
 * not re-encoded by the client.
 */
final class DeliverPspWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [5, 15];

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(public array $payload) {}

    public function handle(Http $http, MockPspSettings $settings): void
    {
        $body = json_encode($this->payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $response = $http
            ->timeout(5)
            ->withHeaders([
                WebhookSignature::HEADER => WebhookSignature::sign($body, $settings->webhookSecret(), time()),
            ])
            ->withBody($body, 'application/json')
            ->post($settings->webhookUrl());

        if (! $response->successful()) {
            Log::warning('Mock PSP webhook delivery rejected.', [
                'status' => $response->status(),
                'event_id' => $this->payload['event_id'] ?? null,
            ]);

            $response->throw(); // let the queue retry
        }
    }
}
