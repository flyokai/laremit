<?php

declare(strict_types=1);

use App\Domain\Billing\Enums\PaymentIntentStatus;
use App\Domain\Billing\Enums\SubscriptionStatus;
use App\Domain\Billing\Exceptions\PspUnavailable;
use App\Domain\Billing\Jobs\ChargeJob;
use App\Domain\Billing\Models\LedgerEntry;
use App\Domain\Billing\Models\PaymentIntent;
use App\Domain\Billing\Models\Subscription;
use App\Domain\Billing\Payments\ChargeProcessor;
use App\Domain\Billing\Payments\CreatePaymentIntent;
use App\Domain\Billing\Subscriptions\SubscriptionStateMachine;
use App\Domain\Catalog\Models\Plan;
use App\Domain\Identity\Models\User;
use App\MockPsp\Jobs\DeliverPspWebhook;
use App\MockPsp\Models\PspCharge;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

function flowPlan(int $amountMinor, string $slug = 'monthly'): Plan
{
    return Plan::factory()->create(['amount_minor' => $amountMinor, 'slug' => $slug]);
}

/** @return array<string, string> */
function flowHeaders(): array
{
    return ['Authorization' => 'Bearer test-billing-token', 'Idempotency-Key' => (string) Str::uuid()];
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
            app(ChargeProcessor::class)->process($intentId);

            return;
        } catch (PspUnavailable) {
            // next attempt
        }
    }
}

it('charges, books the ledger, activates the subscription, grants access', function (): void {
    Queue::fake([DeliverPspWebhook::class]);
    $plan = flowPlan(1499);
    $user = User::factory()->create();

    $response = $this->postJson('/v1/payments', [
        'user_id' => $user->id,
        'product' => $plan->product->slug,
        'plan' => $plan->slug,
    ], flowHeaders())->assertStatus(202);

    $intentId = $response->json('payment_intent_id');

    $this->getJson("/v1/payments/{$intentId}", flowHeaders())
        ->assertOk()
        ->assertJsonPath('status', 'succeeded');

    $intent = PaymentIntent::query()->findOrFail($intentId);

    expect($intent->psp_reference)->toStartWith('ch_')
        ->and(LedgerEntry::query()->count())->toBe(2)
        ->and($intent->subscription->status)->toBe(SubscriptionStatus::Active)
        ->and($intent->subscription->current_period_end?->isAfter(CarbonImmutable::now()))->toBeTrue();

    $this->getJson(sprintf('/v1/entitlements?user_id=%d&product=%s', $user->id, $plan->product->slug), flowHeaders())
        ->assertJsonPath('has_access', true);
});

it('handles a decline: intent failed, no ledger, no access', function (): void {
    Queue::fake([DeliverPspWebhook::class]);
    $plan = flowPlan(1002); // %100 == 2 -> declined
    $user = User::factory()->create();

    $response = $this->postJson('/v1/payments', [
        'user_id' => $user->id,
        'product' => $plan->product->slug,
        'plan' => $plan->slug,
    ], flowHeaders())->assertStatus(202);

    $intent = PaymentIntent::query()->findOrFail($response->json('payment_intent_id'));

    expect($intent->status)->toBe(PaymentIntentStatus::Failed)
        ->and($intent->last_error)->toBe('card_declined')
        ->and(LedgerEntry::query()->count())->toBe(0)
        ->and($intent->subscription->status)->toBe(SubscriptionStatus::Incomplete);

    $this->getJson(sprintf('/v1/entitlements?user_id=%d&product=%s', $user->id, $plan->product->slug), flowHeaders())
        ->assertJsonPath('has_access', false);
});

it('refuses a second purchase while a granting subscription exists', function (): void {
    Queue::fake([DeliverPspWebhook::class]);
    $plan = flowPlan(1499);
    $user = User::factory()->create();

    $body = ['user_id' => $user->id, 'product' => $plan->product->slug, 'plan' => $plan->slug];

    $this->postJson('/v1/payments', $body, flowHeaders())->assertStatus(202);
    $this->postJson('/v1/payments', $body, flowHeaders())
        ->assertStatus(409)
        ->assertJsonPath('error', 'already_subscribed');
});

it('resubscribes after cancel on the same subscription row', function (): void {
    Queue::fake([DeliverPspWebhook::class]);
    $plan = flowPlan(1499);
    $user = User::factory()->create();

    $body = ['user_id' => $user->id, 'product' => $plan->product->slug, 'plan' => $plan->slug];
    $this->postJson('/v1/payments', $body, flowHeaders())->assertStatus(202);

    $subscription = Subscription::query()->where('user_id', $user->id)->firstOrFail();
    app(SubscriptionStateMachine::class)->transition($subscription, SubscriptionStatus::Canceled, CarbonImmutable::now());

    // Canceled-but-in-period still grants access, so a re-purchase now is a 409...
    $this->postJson('/v1/payments', $body, flowHeaders())->assertStatus(409);

    // ...but once the paid period lapses, the same row is charged and reactivated.
    Subscription::query()->whereKey($subscription->id)
        ->update(['current_period_end' => CarbonImmutable::now()->subDay()]);

    $this->postJson('/v1/payments', $body, flowHeaders())->assertStatus(202);

    expect(Subscription::query()->where('user_id', $user->id)->count())->toBe(1)
        ->and($subscription->fresh()?->status)->toBe(SubscriptionStatus::Active)
        ->and($subscription->fresh()?->canceled_at)->toBeNull();
});

it('survives timeout-but-charged: N attempts, exactly one charge, one ledger pair', function (): void {
    Queue::fake([ChargeJob::class, DeliverPspWebhook::class]);
    $plan = flowPlan(1001); // %100 == 1 -> timeout, but the charge succeeds
    $user = User::factory()->create();

    $intent = app(CreatePaymentIntent::class)->execute($user, $plan);

    chargeWithRetries($intent->id);

    $intent->refresh();

    expect($intent->status)->toBe(PaymentIntentStatus::Succeeded)
        ->and(PspCharge::query()->count())->toBe(1)
        ->and(PspCharge::query()->where('status', 'succeeded')->count())->toBe(1)
        ->and(LedgerEntry::query()->count())->toBe(2);
});

it('leaves a never-answered charge processing — never guesses failed', function (): void {
    Queue::fake([ChargeJob::class, DeliverPspWebhook::class]);
    $plan = flowPlan(1003); // %100 == 3 -> timeout, nothing recorded
    $user = User::factory()->create();

    $intent = app(CreatePaymentIntent::class)->execute($user, $plan);

    chargeWithRetries($intent->id);

    expect($intent->refresh()->status)->toBe(PaymentIntentStatus::Processing)
        ->and(PspCharge::query()->count())->toBe(0)
        ->and(LedgerEntry::query()->count())->toBe(0);
});

it('lets the webhook settle an ambiguous charge before the retry does', function (): void {
    Queue::fake([ChargeJob::class, DeliverPspWebhook::class]);
    $plan = flowPlan(1001);
    $user = User::factory()->create();

    $intent = app(CreatePaymentIntent::class)->execute($user, $plan);

    // Attempt 1: timeout (but the PSP charged and queued its webhook).
    try {
        app(ChargeProcessor::class)->process($intent->id);
    } catch (PspUnavailable) {
    }

    // The webhook lands before the queue retries.
    $delivered = Queue::pushed(DeliverPspWebhook::class);
    expect($delivered)->toHaveCount(1);
    deliverPspWebhook($delivered->first()->payload)->assertOk();

    expect($intent->refresh()->status)->toBe(PaymentIntentStatus::Succeeded);

    // The retry now finds a settled intent and a replaying PSP: no-op.
    app(ChargeProcessor::class)->process($intent->id);

    expect(PspCharge::query()->count())->toBe(1)
        ->and(LedgerEntry::query()->count())->toBe(2);
});

it('rejects unauthenticated and malformed payment requests', function (): void {
    $plan = flowPlan(1499);

    $this->postJson('/v1/payments', [], ['Idempotency-Key' => 'k'])->assertStatus(401);

    $this->postJson('/v1/payments', [
        'user_id' => 999999,
        'product' => $plan->product->slug,
        'plan' => $plan->slug,
    ], flowHeaders())->assertStatus(422);

    $this->postJson('/v1/payments', [
        'user_id' => User::factory()->create()->id,
        'product' => $plan->product->slug,
        'plan' => 'no-such-plan',
    ], flowHeaders())->assertStatus(422)->assertJsonPath('error', 'unknown_plan');
});
