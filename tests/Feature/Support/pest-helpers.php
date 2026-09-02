<?php

declare(strict_types=1);

use App\Domain\Billing\Webhooks\WebhookSignature;
use Illuminate\Testing\TestResponse;

/**
 * Deliver a PSP webhook payload to the endpoint exactly as the mock PSP
 * would: raw JSON body, HMAC signature over the timestamp and those exact
 * bytes. Shared by the webhook, reconciliation and chaos suites. Lives
 * under tests/Feature so it shares the Pest-context PHPStan exclusion
 * (tech-debt #1) with its call sites.
 *
 * @param  array<string, mixed>  $payload
 */
function deliverPspWebhook(array $payload, ?int $signedAt = null): TestResponse
{
    $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

    return deliverRawPspWebhook($body, WebhookSignature::sign($body, (string) config('billing.webhook_secret'), $signedAt ?? time()));
}

function deliverRawPspWebhook(string $body, ?string $signatureHeader): TestResponse
{
    $server = ['CONTENT_TYPE' => 'application/json'];

    if ($signatureHeader !== null) {
        $server['HTTP_X_PSP_SIGNATURE'] = $signatureHeader;
    }

    return test()->call('POST', '/v1/psp/webhook', [], [], [], $server, $body);
}

/** Deliver one ASSN v2 body ({"signedPayload": "<jws>"}) the way the mock App Store's job would. */
function deliverAppleNotification(string $body): TestResponse
{
    return test()->call('POST', '/v1/iap/apple/notifications', [], [], [], ['CONTENT_TYPE' => 'application/json'], $body);
}

/** Deliver one Pub/Sub push envelope the way the mock Play Store's job would. */
function deliverGoogleNotification(string $body, ?string $token = null): TestResponse
{
    $token ??= (string) config('billing.stores.google.pubsub_token');

    return test()->call('POST', '/v1/iap/google/notifications?token='.urlencode($token), [], [], [], ['CONTENT_TYPE' => 'application/json'], $body);
}

/**
 * Run charge attempts the way the queue would: retry on ambiguity with the
 * same intent (and therefore the same PSP idempotency key), give up after
 * the job's attempt budget.
 */
function chargeWithRetries(int $intentId, int $maxAttempts = 5): void
{
    foreach (range(1, $maxAttempts) as $attempt) {
        try {
            app(App\Domain\Billing\Payments\ChargeProcessor::class)->process($intentId);

            return;
        } catch (App\Domain\Billing\Exceptions\PspUnavailable) {
            // next attempt
        }
    }
}

/**
 * A charge outcome webhook for an intent, shaped exactly as the mock PSP
 * emits it.
 *
 * @return array<string, mixed>
 */
function pspChargeEvent(App\Domain\Billing\Models\PaymentIntent $intent, string $type = 'charge.succeeded', ?string $chargeId = null): array
{
    return [
        'event_id' => 'evt_'.Illuminate\Support\Str::ulid(),
        'type' => $type,
        'created_at' => Carbon\CarbonImmutable::now()->toISOString(),
        'data' => [
            'charge_id' => $chargeId ?? 'ch_'.Illuminate\Support\Str::ulid(),
            'amount_minor' => $intent->amount_minor,
            'currency' => $intent->currency,
            'decline_code' => $type === 'charge.failed' ? 'card_declined' : null,
            'metadata' => ['payment_intent_id' => $intent->id, 'user_id' => $intent->user_id],
        ],
    ];
}

/**
 * A charge.refunded webhook for a settled intent.
 *
 * @return array<string, mixed>
 */
function pspRefundEvent(App\Domain\Billing\Models\PaymentIntent $intent, ?int $amountMinor = null, ?string $refundId = null, ?string $chargeId = null): array
{
    return [
        'event_id' => 'evt_'.Illuminate\Support\Str::ulid(),
        'type' => 'charge.refunded',
        'created_at' => Carbon\CarbonImmutable::now()->toISOString(),
        'data' => [
            'refund_id' => $refundId ?? 're_'.Illuminate\Support\Str::ulid(),
            'charge_id' => $chargeId ?? (string) $intent->psp_reference,
            'amount_minor' => $amountMinor ?? $intent->amount_minor,
            'currency' => $intent->currency,
            'reason' => 'requested_by_customer',
            'metadata' => ['payment_intent_id' => $intent->id, 'user_id' => $intent->user_id],
        ],
    ];
}

/**
 * A valid domain event for outbox tests; override only what the case is
 * about. Keys default to unique so tests create as many facts as they mean to.
 *
 * @param  array<string, mixed>  $overrides
 */
function outboxDomainEvent(array $overrides = []): App\Domain\Outbox\DomainEvent
{
    $arguments = array_merge([
        'type' => 'billing.subscription.activated',
        'aggregateType' => 'subscription',
        'aggregateId' => '1',
        'idempotencyKey' => 'test:'.Illuminate\Support\Str::ulid(),
        'userId' => 7,
        'product' => 'edtech',
        'occurredAt' => Carbon\CarbonImmutable::now(),
        'payload' => ['subscription_id' => 1],
    ], $overrides);

    return new App\Domain\Outbox\DomainEvent(...$arguments);
}

/**
 * Swap the event buffer for the in-memory fake and return it, so outbox and
 * relay tests observe the stream without Redis. Must run before anything
 * resolves the Ingestor.
 */
function fakeEventBuffer(?Tests\Support\FakeEventBuffer $buffer = null): Tests\Support\FakeEventBuffer
{
    $buffer ??= new Tests\Support\FakeEventBuffer;

    app()->instance(App\Domain\Events\Contracts\EventBuffer::class, $buffer);

    return $buffer;
}

/**
 * Take every store notification the mocks have queued, reset the fake,
 * and deliver them to the app in the given order (default: as queued).
 * Returns the responses so a test can inspect the duplicate flag.
 *
 * @param  (callable(list<App\MockStores\Jobs\DeliverStoreNotification>): list<App\MockStores\Jobs\DeliverStoreNotification>)|null  $reorder
 * @return list<TestResponse>
 */
function drainStoreNotifications(?callable $reorder = null): array
{
    /** @var list<App\MockStores\Jobs\DeliverStoreNotification> $jobs */
    $jobs = Illuminate\Support\Facades\Queue::pushed(App\MockStores\Jobs\DeliverStoreNotification::class)->values()->all();

    Illuminate\Support\Facades\Queue::fake([App\MockStores\Jobs\DeliverStoreNotification::class, App\MockPsp\Jobs\DeliverPspWebhook::class]);

    if ($reorder !== null) {
        $jobs = $reorder($jobs);
    }

    $responses = [];

    foreach ($jobs as $job) {
        $responses[] = $job->store === App\Domain\Billing\Enums\Store::Apple
            ? deliverAppleNotification($job->body)
            : deliverGoogleNotification($job->body);
    }

    return $responses;
}

/**
 * Chaos seed: deterministic by default so the fast pipeline never flakes and
 * a failure reproduces byte for byte. The nightly roulette overrides it via
 * CHAOS_SEED to walk fresh orderings — the invariants under test must hold
 * for EVERY seed, so a red nightly is a real bug, and this prints the seed
 * that found it.
 */
function chaosSeed(int $default): int
{
    $override = getenv('CHAOS_SEED');
    $seed = ($override === false || $override === '') ? $default : (int) $override;

    if ($seed !== $default) {
        fwrite(STDERR, "\nCHAOS_SEED={$seed} (default {$default}) — export it to reproduce this run.\n");
    }

    return $seed;
}
