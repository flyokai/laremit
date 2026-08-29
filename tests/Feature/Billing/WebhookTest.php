<?php

declare(strict_types=1);

use App\Domain\Billing\Enums\PaymentIntentStatus;
use App\Domain\Billing\Enums\Store;
use App\Domain\Billing\Enums\WebhookEventStatus;
use App\Domain\Billing\Models\LedgerEntry;
use App\Domain\Billing\Models\PaymentIntent;
use App\Domain\Billing\Models\WebhookEvent;
use App\Domain\Billing\Webhooks\WebhookSignature;
use Carbon\CarbonImmutable;

/*
|--------------------------------------------------------------------------
| The edge: verify -> persist raw -> 200 -> queue
|--------------------------------------------------------------------------
*/

it('rejects a bad or missing signature', function (): void {
    $this->postJson('/v1/psp/webhook', ['event_id' => 'evt_x'])->assertStatus(401);

    deliverRawPspWebhook('{}', 'wrong')->assertStatus(401)->assertJsonPath('error', 'invalid_signature');
    deliverRawPspWebhook('{}', 't=1,v1=')->assertStatus(401);
});

it('rejects a genuine signature whose timestamp is outside tolerance', function (): void {
    $intent = PaymentIntent::factory()->processing()->create();
    $event = pspChargeEvent($intent);

    deliverPspWebhook($event, time() - 3600)->assertStatus(401)->assertJsonPath('error', 'stale_timestamp');
    deliverPspWebhook($event, time() + 3600)->assertStatus(401)->assertJsonPath('error', 'stale_timestamp');

    expect(WebhookEvent::query()->count())->toBe(0)
        ->and($intent->refresh()->status)->toBe(PaymentIntentStatus::Processing);

    // Inside the window is fine — clocks drift.
    deliverPspWebhook($event, time() - 120)->assertOk();
});

it('fails closed when no webhook secret is configured', function (): void {
    config()->set('billing.webhook_secret', null);

    $this->postJson('/v1/psp/webhook', [])->assertStatus(503);
});

it('rejects malformed JSON that carries a valid signature', function (): void {
    $body = '{broken';

    deliverRawPspWebhook($body, WebhookSignature::sign($body, (string) config('billing.webhook_secret'), time()))
        ->assertStatus(400)
        ->assertJsonPath('error', 'malformed_json');
});

it('rejects a payload it cannot identify', function (): void {
    deliverPspWebhook(['type' => 'charge.succeeded'])->assertStatus(400)->assertJsonPath('error', 'malformed_payload');
    deliverPspWebhook(['event_id' => 'evt_1'])->assertStatus(400)->assertJsonPath('error', 'malformed_payload');

    expect(WebhookEvent::query()->count())->toBe(0);
});

it('persists the exact raw bytes before doing anything with them', function (): void {
    $intent = PaymentIntent::factory()->processing()->create();
    $event = pspChargeEvent($intent);
    $body = json_encode($event, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

    deliverRawPspWebhook($body, WebhookSignature::sign($body, (string) config('billing.webhook_secret'), time()))
        ->assertOk()
        ->assertJsonPath('received', true)
        ->assertJsonPath('duplicate', false);

    $stored = WebhookEvent::query()->sole();

    expect($stored->provider)->toBe(Store::Psp)
        ->and($stored->provider_event_id)->toBe($event['event_id'])
        ->and($stored->type)->toBe('charge.succeeded')
        ->and($stored->payload)->toBe($body)
        ->and($stored->provider_created_at?->equalTo(CarbonImmutable::parse($event['created_at'])->startOfSecond()))->toBeTrue()
        ->and($stored->status)->toBe(WebhookEventStatus::Processed)
        ->and($stored->outcome)->toBe('applied')
        ->and($stored->attempts)->toBe(1)
        ->and($stored->processed_at)->not->toBeNull();
});

it('dedupes redeliveries at the edge: one row, one application', function (): void {
    $intent = PaymentIntent::factory()->processing()->create();
    $event = pspChargeEvent($intent);

    deliverPspWebhook($event)->assertOk()->assertJsonPath('duplicate', false);
    deliverPspWebhook($event)->assertOk()->assertJsonPath('duplicate', true);
    deliverPspWebhook($event)->assertOk()->assertJsonPath('duplicate', true);

    expect(WebhookEvent::query()->count())->toBe(1)
        ->and(WebhookEvent::query()->sole()->attempts)->toBe(1)
        ->and(LedgerEntry::query()->count())->toBe(2)
        ->and($intent->refresh()->status)->toBe(PaymentIntentStatus::Succeeded);
});

it('dispatches on "still pending", not on "just inserted": a crash between insert and dispatch is healed by the retry', function (): void {
    $intent = PaymentIntent::factory()->processing()->create();
    $event = pspChargeEvent($intent);
    $body = json_encode($event, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

    // The process died after persisting and before dispatching: the row
    // exists, nothing ever ran, the provider never got its 200.
    WebhookEvent::query()->create([
        'provider' => Store::Psp,
        'provider_event_id' => $event['event_id'],
        'type' => 'charge.succeeded',
        'payload' => $body,
        'received_at' => CarbonImmutable::now(),
        'status' => WebhookEventStatus::Pending,
    ]);

    // The provider retries. A wasRecentlyCreated check would answer 200
    // and never process it; branching on status processes it now.
    deliverPspWebhook($event)->assertOk()->assertJsonPath('duplicate', true);

    expect(WebhookEvent::query()->sole()->status)->toBe(WebhookEventStatus::Processed)
        ->and($intent->refresh()->status)->toBe(PaymentIntentStatus::Succeeded);
});

it('acknowledges and ignores event types it does not model', function (): void {
    $intent = PaymentIntent::factory()->processing()->create();

    deliverPspWebhook(pspChargeEvent($intent, 'charge.disputed'))->assertOk();

    $stored = WebhookEvent::query()->sole();

    expect($stored->status)->toBe(WebhookEventStatus::Processed)
        ->and($stored->outcome)->toBe('ignored')
        ->and($intent->refresh()->status)->toBe(PaymentIntentStatus::Processing);
});

it('discards a well-signed payload the handler cannot parse, and keeps it', function (): void {
    $event = pspChargeEvent(PaymentIntent::factory()->processing()->create());
    unset($event['data']['metadata']);

    deliverPspWebhook($event)->assertOk();

    $stored = WebhookEvent::query()->sole();

    expect($stored->status)->toBe(WebhookEventStatus::Discarded)
        ->and($stored->outcome)->toBe('malformed');
});

/*
|--------------------------------------------------------------------------
| The funnel: the Phase 3 decision table, row by row, now with a record
|--------------------------------------------------------------------------
*/

it('applies a verified charge.succeeded end to end', function (): void {
    $intent = PaymentIntent::factory()->processing()->create();
    $event = pspChargeEvent($intent);

    deliverPspWebhook($event)->assertOk()->assertJsonPath('received', true);

    $intent->refresh();

    expect($intent->status)->toBe(PaymentIntentStatus::Succeeded)
        ->and($intent->psp_reference)->toBe($event['data']['charge_id'])
        ->and(LedgerEntry::query()->count())->toBe(2);
});

it('absorbs duplicate deliveries under different event ids: one ledger pair, one activation', function (): void {
    $intent = PaymentIntent::factory()->processing()->create();
    $event = pspChargeEvent($intent);

    deliverPspWebhook($event)->assertOk();

    // Same charge, a "new" delivery id — past the edge's dedup, into the funnel's.
    $again = $event;
    $again['event_id'] = 'evt_'.Illuminate\Support\Str::ulid();
    deliverPspWebhook($again)->assertOk()->assertJsonPath('duplicate', false);

    expect(LedgerEntry::query()->count())->toBe(2)
        ->and($intent->refresh()->status)->toBe(PaymentIntentStatus::Succeeded)
        ->and(WebhookEvent::query()->where('provider_event_id', $again['event_id'])->sole()->outcome)->toBe('duplicate');
});

it('lets the terminal state win over a late conflicting event, and records the conflict', function (): void {
    $intent = PaymentIntent::factory()->processing()->create();
    $success = pspChargeEvent($intent);

    deliverPspWebhook($success)->assertOk();

    $conflict = pspChargeEvent($intent, 'charge.failed');
    deliverPspWebhook($conflict)->assertOk();

    expect($intent->refresh()->status)->toBe(PaymentIntentStatus::Succeeded)
        ->and($intent->psp_reference)->toBe($success['data']['charge_id'])
        ->and(LedgerEntry::query()->count())->toBe(2)
        ->and(WebhookEvent::query()->where('provider_event_id', $conflict['event_id'])->sole()->outcome)->toBe('conflict');
});

it('applies charge.failed without touching the ledger', function (): void {
    $intent = PaymentIntent::factory()->processing()->create();

    deliverPspWebhook(pspChargeEvent($intent, 'charge.failed'))->assertOk();

    expect($intent->refresh()->status)->toBe(PaymentIntentStatus::Failed)
        ->and($intent->last_error)->toBe('card_declined')
        ->and(LedgerEntry::query()->count())->toBe(0);
});

it('ignores an event whose amount disagrees with the intent', function (): void {
    $intent = PaymentIntent::factory()->processing()->create();
    $event = pspChargeEvent($intent);
    $event['data']['amount_minor'] = $intent->amount_minor + 1;

    deliverPspWebhook($event)->assertOk();

    expect($intent->refresh()->status)->toBe(PaymentIntentStatus::Processing)
        ->and(LedgerEntry::query()->count())->toBe(0)
        ->and(WebhookEvent::query()->sole()->outcome)->toBe('amount_mismatch');
});

it('accepts but discards an event for an unknown intent', function (): void {
    $intent = PaymentIntent::factory()->processing()->create();
    $event = pspChargeEvent($intent);
    $event['data']['metadata']['payment_intent_id'] = 424242;

    deliverPspWebhook($event)->assertOk();

    expect($intent->refresh()->status)->toBe(PaymentIntentStatus::Processing)
        ->and(WebhookEvent::query()->sole()->outcome)->toBe('unknown_intent');
});

it('settles an intent the ChargeJob has not even claimed yet', function (): void {
    // Webhook-first ordering: pending -> succeeded directly.
    $intent = PaymentIntent::factory()->create();

    deliverPspWebhook(pspChargeEvent($intent))->assertOk();

    expect($intent->refresh()->status)->toBe(PaymentIntentStatus::Succeeded)
        ->and(LedgerEntry::query()->count())->toBe(2);
});
