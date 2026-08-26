<?php

declare(strict_types=1);

use App\MockPsp\Jobs\DeliverPspWebhook;
use App\MockPsp\Models\PspCharge;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

function pspCharge(array $body, string $key): TestResponse
{
    return test()->postJson('/mock-psp/v1/charges', $body, ['Idempotency-Key' => $key]);
}

/** @return array<string, mixed> */
function pspBody(int $amount, array $metadata = []): array
{
    return [
        'amount_minor' => $amount,
        'currency' => 'USD',
        'metadata' => $metadata + ['payment_intent_id' => 1, 'user_id' => 1],
    ];
}

beforeEach(function (): void {
    Queue::fake([DeliverPspWebhook::class]);
    config()->set('mockpsp.timeout.sleep_seconds', 0);
});

it('charges successfully and fires a charge.succeeded webhook', function (): void {
    $response = pspCharge(pspBody(1000), 'psp-key-1')->assertStatus(201);

    expect($response->json('status'))->toBe('succeeded')
        ->and(PspCharge::query()->count())->toBe(1);

    Queue::assertPushed(DeliverPspWebhook::class, function (DeliverPspWebhook $job): bool {
        return ($job->payload['type'] ?? null) === 'charge.succeeded'
            && is_string($job->payload['event_id'] ?? null)
            && ($job->payload['data']['metadata']['payment_intent_id'] ?? null) === 1;
    });
});

it('declines by amount convention and fires charge.failed', function (): void {
    pspCharge(pspBody(1002), 'psp-key-2')
        ->assertStatus(402)
        ->assertJsonPath('decline_code', 'card_declined');

    Queue::assertPushed(DeliverPspWebhook::class, fn (DeliverPspWebhook $job): bool => ($job->payload['type'] ?? null) === 'charge.failed');
});

it('replays a repeated idempotency key byte-for-byte without re-deciding', function (): void {
    $first = pspCharge(pspBody(1000), 'psp-key-3')->assertStatus(201);
    $second = pspCharge(pspBody(1000), 'psp-key-3')->assertStatus(201);

    expect($second->json())->toBe($first->json())
        ->and(PspCharge::query()->count())->toBe(1);

    // The replay must not fire webhooks again.
    Queue::assertPushed(DeliverPspWebhook::class, 1);
});

it('rejects the same key with a different request', function (): void {
    pspCharge(pspBody(1000), 'psp-key-4')->assertStatus(201);

    pspCharge(pspBody(2000), 'psp-key-4')
        ->assertStatus(409)
        ->assertJsonPath('error', 'idempotency_key_reuse');
});

it('on a timeout-but-charged amount, records the charge and its webhook anyway', function (): void {
    // HTTP transport: the sleep is zeroed for tests, so we get the "truth"
    // response the real caller would never see.
    pspCharge(pspBody(1001), 'psp-key-5')->assertStatus(201);

    expect(PspCharge::query()->where('status', 'succeeded')->count())->toBe(1);
    Queue::assertPushed(DeliverPspWebhook::class, 1);

    // The retry with the same key replays the stored success — the whole
    // point of the ambiguous-timeout simulation.
    pspCharge(pspBody(1001), 'psp-key-5')->assertStatus(201);
    expect(PspCharge::query()->count())->toBe(1);
});

it('on a timeout-lost amount, records nothing and answers 504', function (): void {
    pspCharge(pspBody(1003), 'psp-key-6')->assertStatus(504);

    expect(PspCharge::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('duplicates webhooks when configured to', function (): void {
    config()->set('mockpsp.webhook.duplicate_rate', 1.0);

    pspCharge(pspBody(1000), 'psp-key-7')->assertStatus(201);

    Queue::assertPushed(DeliverPspWebhook::class, 2);
});

it('honours metadata.force over the amount convention', function (): void {
    pspCharge(pspBody(1000, ['force' => 'declined']), 'psp-key-8')->assertStatus(402);
});

it('exposes runtime-configurable settings', function (): void {
    $this->postJson('/mock-psp/config', ['outcomes' => ['declined_rate' => 0.5]])
        ->assertOk()
        ->assertJsonPath('outcomes.declined_rate', 0.5);

    $this->getJson('/mock-psp/config')->assertJsonPath('outcomes.declined_rate', 0.5);

    $this->deleteJson('/mock-psp/config')->assertJsonPath('outcomes.declined_rate', 0);
});

it('requires an idempotency key', function (): void {
    $this->postJson('/mock-psp/v1/charges', pspBody(1000))
        ->assertStatus(400)
        ->assertJsonPath('error', 'missing_idempotency_key');
});
