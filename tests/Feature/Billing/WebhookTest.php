<?php

declare(strict_types=1);

use App\Domain\Billing\Enums\PaymentIntentStatus;
use App\Domain\Billing\Models\LedgerEntry;
use App\Domain\Billing\Models\PaymentIntent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/** @return array<string, mixed> */
function webhookEvent(PaymentIntent $intent, string $type = 'charge.succeeded', ?string $chargeId = null): array
{
    return [
        'event_id' => 'evt_'.Str::ulid(),
        'type' => $type,
        'created_at' => CarbonImmutable::now()->toISOString(),
        'data' => [
            'charge_id' => $chargeId ?? 'ch_'.Str::ulid(),
            'amount_minor' => $intent->amount_minor,
            'currency' => $intent->currency,
            'decline_code' => $type === 'charge.failed' ? 'card_declined' : null,
            'metadata' => ['payment_intent_id' => $intent->id, 'user_id' => $intent->user_id],
        ],
    ];
}

it('rejects a bad or missing signature', function (): void {
    $this->postJson('/v1/psp/webhook', ['event_id' => 'evt_x'])->assertStatus(401);

    $this->call('POST', '/v1/psp/webhook', [], [], [], [
        'HTTP_X_PSP_SIGNATURE' => 'wrong',
        'CONTENT_TYPE' => 'application/json',
    ], '{}')->assertStatus(401);
});

it('fails closed when no webhook secret is configured', function (): void {
    config()->set('billing.webhook_secret', null);

    $this->postJson('/v1/psp/webhook', [])->assertStatus(503);
});

it('rejects malformed JSON that carries a valid signature', function (): void {
    $body = '{broken';

    $this->call('POST', '/v1/psp/webhook', [], [], [], [
        'HTTP_X_PSP_SIGNATURE' => hash_hmac('sha256', $body, (string) config('billing.webhook_secret')),
        'CONTENT_TYPE' => 'application/json',
    ], $body)->assertStatus(400);
});

it('applies a verified charge.succeeded end to end', function (): void {
    $intent = PaymentIntent::factory()->processing()->create();
    $event = webhookEvent($intent);

    deliverPspWebhook($event)->assertOk()->assertJsonPath('received', true);

    $intent->refresh();

    expect($intent->status)->toBe(PaymentIntentStatus::Succeeded)
        ->and($intent->psp_reference)->toBe($event['data']['charge_id'])
        ->and(LedgerEntry::query()->count())->toBe(2);
});

it('absorbs duplicate deliveries: one ledger pair, one activation', function (): void {
    $intent = PaymentIntent::factory()->processing()->create();
    $event = webhookEvent($intent);

    deliverPspWebhook($event)->assertOk();
    deliverPspWebhook($event)->assertOk();
    deliverPspWebhook($event)->assertOk();

    expect(LedgerEntry::query()->count())->toBe(2)
        ->and($intent->refresh()->status)->toBe(PaymentIntentStatus::Succeeded);
});

it('lets the terminal state win over a late conflicting event', function (): void {
    $intent = PaymentIntent::factory()->processing()->create();
    $success = webhookEvent($intent);

    deliverPspWebhook($success)->assertOk();

    // A contradictory failed event for a different charge id arrives late.
    deliverPspWebhook(webhookEvent($intent, 'charge.failed'))->assertOk();

    expect($intent->refresh()->status)->toBe(PaymentIntentStatus::Succeeded)
        ->and($intent->psp_reference)->toBe($success['data']['charge_id'])
        ->and(LedgerEntry::query()->count())->toBe(2);
});

it('applies charge.failed without touching the ledger', function (): void {
    $intent = PaymentIntent::factory()->processing()->create();

    deliverPspWebhook(webhookEvent($intent, 'charge.failed'))->assertOk();

    expect($intent->refresh()->status)->toBe(PaymentIntentStatus::Failed)
        ->and($intent->last_error)->toBe('card_declined')
        ->and(LedgerEntry::query()->count())->toBe(0);
});

it('ignores an event whose amount disagrees with the intent', function (): void {
    $intent = PaymentIntent::factory()->processing()->create();
    $event = webhookEvent($intent);
    $event['data']['amount_minor'] = $intent->amount_minor + 1;

    deliverPspWebhook($event)->assertOk();

    expect($intent->refresh()->status)->toBe(PaymentIntentStatus::Processing)
        ->and(LedgerEntry::query()->count())->toBe(0);
});

it('accepts but discards an event for an unknown intent', function (): void {
    $intent = PaymentIntent::factory()->processing()->create();
    $event = webhookEvent($intent);
    $event['data']['metadata']['payment_intent_id'] = 424242;

    deliverPspWebhook($event)->assertOk();

    expect($intent->refresh()->status)->toBe(PaymentIntentStatus::Processing);
});

it('settles an intent the ChargeJob has not even claimed yet', function (): void {
    // Webhook-first ordering: pending -> succeeded directly.
    $intent = PaymentIntent::factory()->create();

    deliverPspWebhook(webhookEvent($intent))->assertOk();

    expect($intent->refresh()->status)->toBe(PaymentIntentStatus::Succeeded)
        ->and(LedgerEntry::query()->count())->toBe(2);
});
