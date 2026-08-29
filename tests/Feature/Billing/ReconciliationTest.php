<?php

declare(strict_types=1);

use App\Domain\Billing\Enums\LedgerAccount;
use App\Domain\Billing\Enums\PaymentIntentStatus;
use App\Domain\Billing\Enums\Store;
use App\Domain\Billing\Enums\SubscriptionStatus;
use App\Domain\Billing\Enums\WebhookEventStatus;
use App\Domain\Billing\Exceptions\PspUnavailable;
use App\Domain\Billing\Jobs\ChargeJob;
use App\Domain\Billing\Ledger\Ledger;
use App\Domain\Billing\Models\LedgerEntry;
use App\Domain\Billing\Models\PaymentIntent;
use App\Domain\Billing\Models\ReconciliationRun;
use App\Domain\Billing\Models\Subscription;
use App\Domain\Billing\Models\WebhookEvent;
use App\Domain\Billing\Payments\ChargeProcessor;
use App\Domain\Billing\Payments\CreatePaymentIntent;
use App\Domain\Billing\Reconciliation\Reconciler;
use App\Domain\Billing\Reconciliation\ReconciliationReport;
use App\Domain\Billing\Reconciliation\Sweeps\PendingWebhookSweep;
use App\Domain\Billing\Reconciliation\Sweeps\PspChargeSweep;
use App\Domain\Billing\Reconciliation\Sweeps\StoreSubscriptionSweep;
use App\Domain\Billing\Reconciliation\Sweeps\StuckIntentSweep;
use App\Domain\Billing\Stores\StoreProductId;
use App\Domain\Catalog\Models\Plan;
use App\Domain\Catalog\Models\Product;
use App\Domain\Identity\Models\User;
use App\MockPsp\Jobs\DeliverPspWebhook;
use App\MockPsp\MockPsp;
use App\MockStores\Apple\MockAppStore;
use App\MockStores\Jobs\DeliverStoreNotification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;

function reconcile(): ReconciliationRun
{
    return app(Reconciler::class)->run();
}

/**
 * @template T of object
 *
 * @param  class-string<T>  $sweep
 */
function runSweep(string $sweep): ReconciliationReport
{
    $report = new ReconciliationReport;
    $now = CarbonImmutable::now();

    app($sweep)->sweep($report, $now, $now->subHours(26));

    return $report;
}

/** An intent charged once at the PSP with no webhook delivered: the outcome exists only on their side. */
function chargedButUnsettled(int $amount = 1001): PaymentIntent
{
    $plan = Plan::factory()->create(['amount_minor' => $amount, 'slug' => 'p'.$amount.random_int(1, 999999)]);
    $intent = app(CreatePaymentIntent::class)->execute(User::factory()->create(), $plan);

    try {
        app(ChargeProcessor::class)->process($intent->id);
    } catch (PspUnavailable) {
        // timeout-but-charged: the PSP has it, we do not
    }

    return $intent->refresh();
}

beforeEach(function (): void {
    Queue::fake([ChargeJob::class, DeliverPspWebhook::class, DeliverStoreNotification::class]);
});

it('settles an intent whose outcome webhook was lost (theirs -> ours)', function (): void {
    $intent = chargedButUnsettled();

    expect($intent->status)->toBe(PaymentIntentStatus::Processing);

    $run = reconcile();

    expect($intent->refresh()->status)->toBe(PaymentIntentStatus::Succeeded)
        ->and($intent->psp_reference)->toStartWith('ch_')
        ->and($intent->subscription->status)->toBe(SubscriptionStatus::Active)
        ->and(LedgerEntry::query()->count())->toBe(2)
        ->and($run->fixed)->toBe(1)
        ->and($run->unresolved)->toBe(0)
        ->and($run->findings)->toBe(['missed_charge_outcome' => 1])
        ->and($run->scanned['psp_charges'])->toBe(1);

    // Converged: a second run finds nothing to do.
    expect(reconcile()->fixed)->toBe(0);
});

it('asks the provider about a stuck intent by our key and settles it (ours -> theirs)', function (): void {
    $intent = PaymentIntent::factory()->processing()->create(['created_at' => CarbonImmutable::now()->subHour()]);

    // The PSP charged under our key; the response and the webhook both vanished.
    app(MockPsp::class)->charge($intent->psp_idempotency_key, $intent->amount_minor, $intent->currency, [
        'payment_intent_id' => $intent->id,
        'user_id' => $intent->user_id,
    ]);

    $report = runSweep(StuckIntentSweep::class);

    expect($intent->refresh()->status)->toBe(PaymentIntentStatus::Succeeded)
        ->and($report->count('settled_from_provider'))->toBe(1)
        ->and($report->fixed)->toBe(1);

    Queue::assertNotPushed(ChargeJob::class);
});

it('re-dispatches a stuck charge the provider has never heard of, bounded by recovery attempts', function (): void {
    config()->set('billing.reconciliation.max_recovery_attempts', 2);
    $intent = PaymentIntent::factory()->processing()->create(['created_at' => CarbonImmutable::now()->subHour()]);

    $first = runSweep(StuckIntentSweep::class);
    $second = runSweep(StuckIntentSweep::class);

    Queue::assertPushed(ChargeJob::class, 2);

    expect($first->count('redispatched_charge'))->toBe(1)
        ->and($first->unresolved)->toBe(0)
        ->and($intent->refresh()->recovery_attempts)->toBe(2)
        ->and($intent->last_recovered_at)->not->toBeNull()
        ->and($second->count('redispatched_charge'))->toBe(1);

    // Budget spent: escalate, stop retrying.
    $third = runSweep(StuckIntentSweep::class);

    Queue::assertPushed(ChargeJob::class, 2);

    expect($third->count('stuck_intent'))->toBe(1)
        ->and($third->unresolved)->toBe(1)
        ->and($intent->refresh()->status)->toBe(PaymentIntentStatus::Processing);
});

it('leaves a young in-flight intent alone', function (): void {
    PaymentIntent::factory()->processing()->create();

    $report = runSweep(StuckIntentSweep::class);

    expect($report->scanned)->toBe([]);
    Queue::assertNotPushed(ChargeJob::class);
});

it('flags a charge for an intent it cannot find', function (): void {
    app(MockPsp::class)->charge('key-from-nowhere', 999, 'USD', ['payment_intent_id' => 424242]);

    $report = runSweep(PspChargeSweep::class);

    expect($report->count('orphan_charge'))->toBe(1)
        ->and($report->unresolved)->toBe(1)
        ->and($report->fixed)->toBe(0);
});

it('never overwrites a settled intent: contradictions are flagged, terminal wins', function (): void {
    $intent = PaymentIntent::factory()->processing()->create();

    $response = app(MockPsp::class)->charge($intent->psp_idempotency_key, $intent->amount_minor, $intent->currency, [
        'payment_intent_id' => $intent->id,
        'user_id' => $intent->user_id,
    ]);

    // We recorded a decline for the same charge — an impossible book, planted.
    PaymentIntent::query()->whereKey($intent->id)->update([
        'status' => PaymentIntentStatus::Failed->value,
        'psp_reference' => $response->body['charge_id'],
    ]);

    $report = runSweep(PspChargeSweep::class);

    expect($report->count('status_drift'))->toBe(1)
        ->and($report->unresolved)->toBe(1)
        ->and($intent->refresh()->status)->toBe(PaymentIntentStatus::Failed)
        ->and(LedgerEntry::query()->count())->toBe(0);
});

it('books a refund the webhook never delivered and revokes', function (): void {
    $intent = chargedButUnsettled(1500);
    chargeWithRetries($intent->id); // settled by the idempotent replay

    expect($intent->refresh()->status)->toBe(PaymentIntentStatus::Succeeded);

    app(MockPsp::class)->refund((string) $intent->psp_reference, null, 'requested_by_customer');

    $run = reconcile();

    expect(LedgerEntry::query()->count())->toBe(4)
        ->and(app(Ledger::class)->balance(LedgerAccount::Refunds, 'USD')->minor)->toBe(1500)
        ->and($intent->refresh()->refunded_minor)->toBe(1500)
        ->and($intent->subscription->status)->toBe(SubscriptionStatus::Revoked)
        ->and($run->findings)->toBe(['missed_refund' => 1])
        ->and($run->fixed)->toBe(1);

    expect(reconcile()->fixed)->toBe(0);
});

it('re-syncs a drifted store subscription from the store', function (): void {
    $product = Product::factory()->create(['slug' => 'edtech']);
    Plan::factory()->for($product)->create(['slug' => 'monthly']);
    $user = User::factory()->create();

    $purchase = app(MockAppStore::class)->purchase(StoreProductId::of('edtech', 'monthly'), $user->app_account_token);
    drainStoreNotifications();
    Queue::fake([ChargeJob::class, DeliverPspWebhook::class, DeliverStoreNotification::class]);

    // The store cancelled; the notification never arrived.
    app(MockAppStore::class)->act($purchase->identifier, 'cancel');

    $before = Subscription::query()->sole();
    expect($before->status)->toBe(SubscriptionStatus::Active);

    $report = runSweep(StoreSubscriptionSweep::class);

    expect(Subscription::query()->sole()->status)->toBe(SubscriptionStatus::Canceled)
        ->and($report->count('store_drift'))->toBe(1)
        ->and($report->fixed)->toBe(1)
        ->and($report->scanned['store_subscriptions'])->toBe(1);

    // Converged, and the watermark moved: the late notification is now stale.
    expect(runSweep(StoreSubscriptionSweep::class)->fixed)->toBe(0);
});

it('flags a store subscription the store has no record of', function (): void {
    Subscription::factory()->fromStore(Store::Apple)->create();

    $report = runSweep(StoreSubscriptionSweep::class);

    expect($report->count('orphan_store_subscription'))->toBe(1)
        ->and($report->unresolved)->toBe(1);
});

it('re-queues a pending webhook older than the threshold', function (): void {
    $intent = PaymentIntent::factory()->processing()->create();
    $event = pspChargeEvent($intent);

    $row = WebhookEvent::query()->create([
        'provider' => Store::Psp,
        'provider_event_id' => $event['event_id'],
        'type' => 'charge.succeeded',
        'payload' => json_encode($event, JSON_THROW_ON_ERROR),
        'received_at' => CarbonImmutable::now()->subMinutes(10),
        'status' => WebhookEventStatus::Pending,
    ]);

    $fresh = WebhookEvent::query()->create([
        'provider' => Store::Psp,
        'provider_event_id' => 'evt_fresh',
        'type' => 'charge.succeeded',
        'payload' => '{}',
        'received_at' => CarbonImmutable::now(),
        'status' => WebhookEventStatus::Pending,
    ]);

    $report = runSweep(PendingWebhookSweep::class);

    expect($row->refresh()->status)->toBe(WebhookEventStatus::Processed)
        ->and($intent->refresh()->status)->toBe(PaymentIntentStatus::Succeeded)
        ->and($fresh->refresh()->status)->toBe(WebhookEventStatus::Pending)
        ->and($report->count('redispatched_webhook'))->toBe(1);
});

it('persists every run and the command reports it', function (): void {
    $this->artisan('billing:reconcile')
        ->expectsOutputToContain('No discrepancies')
        ->assertExitCode(0);

    $run = ReconciliationRun::query()->sole();

    expect($run->fixed)->toBe(0)
        ->and($run->unresolved)->toBe(0)
        ->and($run->window_start->isBefore(CarbonImmutable::now()->subHours(25)))->toBeTrue();
});

it('exits non-zero when discrepancies remain', function (): void {
    app(MockPsp::class)->charge('orphan-key', 999, 'USD', []);

    $this->artisan('billing:reconcile')
        ->expectsOutputToContain('UNRESOLVED')
        ->assertExitCode(1);

    expect(ReconciliationRun::query()->sole()->findings)->toBe(['orphan_charge' => 1]);
});
