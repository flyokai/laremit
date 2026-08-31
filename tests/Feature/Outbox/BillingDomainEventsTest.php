<?php

declare(strict_types=1);

use App\Domain\Billing\Enums\Store;
use App\Domain\Billing\Enums\SubscriptionStatus;
use App\Domain\Billing\Models\PaymentIntent;
use App\Domain\Billing\Models\Subscription;
use App\Domain\Billing\Subscriptions\SubscriptionStateMachine;
use App\Domain\Catalog\Models\Plan;
use App\Domain\Identity\Models\User;
use App\Domain\Outbox\Models\OutboxMessage;
use App\MockPsp\Jobs\DeliverPspWebhook;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/**
 * Every state-change funnel publishes its domain events in the same
 * transaction as the change — these tests drive the REAL flows and read the
 * outbox, so a funnel that stops publishing (or publishes twice) fails here.
 */
function purchase(Plan $plan, User $user): PaymentIntent
{
    Queue::fake([DeliverPspWebhook::class]);

    $response = test()->postJson('/v1/payments', [
        'user_id' => $user->id,
        'product' => $plan->product->slug,
        'plan' => $plan->slug,
    ], ['Authorization' => 'Bearer test-billing-token', 'Idempotency-Key' => (string) Str::uuid()])->assertStatus(202);

    return PaymentIntent::query()->findOrFail($response->json('payment_intent_id'));
}

it('publishes payment.succeeded and subscription.activated for a successful purchase', function (): void {
    $plan = Plan::factory()->create(['amount_minor' => 1499]);
    $intent = purchase($plan, User::factory()->create());

    $messages = OutboxMessage::query()->orderBy('id')->get();

    expect($messages->pluck('type')->all())->toBe(['billing.payment.succeeded', 'billing.subscription.activated']);

    [$payment, $activation] = $messages->all();

    expect($payment->idempotency_key)->toBe("payment:{$intent->id}:settled")
        ->and($payment->aggregate_type)->toBe('payment_intent')
        ->and($payment->decodedPayload())->toMatchArray([
            'user_id' => $intent->user_id,
            'product' => $plan->product->slug,
            'amount_minor' => 1499,
            'currency' => $intent->currency,
            'charge_id' => $intent->psp_reference,
            'decline_code' => null,
        ])
        ->and($activation->aggregate_id)->toBe((string) $intent->subscription_id)
        ->and($activation->decodedPayload())->toMatchArray([
            'status' => 'active',
            'previous_status' => 'incomplete',
            'store' => 'psp',
        ]);
});

it('publishes only payment.failed for a decline', function (): void {
    $plan = Plan::factory()->create(['amount_minor' => 1002]); // %100 == 2 -> declined

    purchase($plan, User::factory()->create());

    $messages = OutboxMessage::query()->get();

    expect($messages->pluck('type')->all())->toBe(['billing.payment.failed'])
        ->and($messages->first()?->decodedPayload()['decline_code'])->toBe('card_declined');
});

it('publishes nothing extra when the settle webhook duplicates the synchronous outcome', function (): void {
    $plan = Plan::factory()->create(['amount_minor' => 1499]);
    $intent = purchase($plan, User::factory()->create());

    $before = OutboxMessage::query()->count();

    deliverPspWebhook(pspChargeEvent($intent, 'charge.succeeded', (string) $intent->psp_reference))->assertOk();

    expect(OutboxMessage::query()->count())->toBe($before);
});

it('publishes payment.refunded and subscription.revoked for a full refund', function (): void {
    $plan = Plan::factory()->create(['amount_minor' => 1499]);
    $intent = purchase($plan, User::factory()->create());

    $refund = pspRefundEvent($intent);
    deliverPspWebhook($refund)->assertOk();

    $types = OutboxMessage::query()->orderBy('id')->pluck('type')->all();

    expect($types)->toBe([
        'billing.payment.succeeded',
        'billing.subscription.activated',
        'billing.payment.refunded',
        'billing.subscription.revoked',
    ]);

    $refunded = OutboxMessage::query()->where('type', 'billing.payment.refunded')->firstOrFail();

    expect($refunded->idempotency_key)->toBe('refund:'.$refund['data']['refund_id'])
        ->and($refunded->decodedPayload()['fully_refunded'])->toBeTrue();

    // The same refund delivered again is the same fact: no new messages.
    deliverPspWebhook($refund)->assertOk();

    expect(OutboxMessage::query()->count())->toBe(4);
});

it('publishes a store mirror only when the status actually changes', function (): void {
    $subscription = Subscription::factory()->create([
        'status' => SubscriptionStatus::Active,
        'store' => Store::Apple,
        'store_original_transaction_id' => 'orig_1',
        'last_event_at' => null,
    ]);

    $machine = app(SubscriptionStateMachine::class);
    $at = CarbonImmutable::now();

    // The store says canceled: a change, an event.
    $machine->mirror($subscription, SubscriptionStatus::Canceled, $at);

    // The hourly re-sync confirms it: a watermark advance, not a fact.
    $machine->mirror($subscription, SubscriptionStatus::Canceled, $at->addHour());

    $messages = OutboxMessage::query()->get();

    expect($messages->pluck('type')->all())->toBe(['billing.subscription.canceled'])
        ->and($messages->first()?->decodedPayload()['previous_status'])->toBe('active');
});
