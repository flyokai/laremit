<?php

declare(strict_types=1);

use App\Domain\Billing\Enums\LedgerAccount;
use App\Domain\Billing\Enums\PaymentIntentStatus;
use App\Domain\Billing\Enums\Store;
use App\Domain\Billing\Enums\SubscriptionStatus;
use App\Domain\Billing\Exceptions\PspUnavailable;
use App\Domain\Billing\Jobs\ChargeJob;
use App\Domain\Billing\Ledger\Ledger;
use App\Domain\Billing\Models\PaymentIntent;
use App\Domain\Billing\Models\Subscription;
use App\Domain\Billing\Payments\ChargeProcessor;
use App\Domain\Billing\Payments\CreatePaymentIntent;
use App\Domain\Billing\Reconciliation\Reconciler;
use App\Domain\Billing\Stores\StoreClient;
use App\Domain\Billing\Stores\StoreProductId;
use App\Domain\Catalog\Models\Plan;
use App\Domain\Catalog\Models\Product;
use App\Domain\Identity\Models\User;
use App\MockPsp\Jobs\DeliverPspWebhook;
use App\MockPsp\MockPsp;
use App\MockStores\Apple\MockAppStore;
use App\MockStores\Google\MockPlayStore;
use App\MockStores\Jobs\DeliverStoreNotification;
use App\MockStores\Models\StoreSubscription;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/**
 * The Phase 4 deliverable: drop 20% of webhooks at random — PSP charge
 * outcomes, refunds, App Store and Play Store notifications alike — and
 * one reconciliation run converges state and reports exactly how many
 * discrepancies that took.
 *
 * Seeded like the Phase 3 chaos test, so a failure reproduces.
 */
it('converges after dropping 20% of all webhooks, and reports how many discrepancies it fixed', function (): void {
    mt_srand(20260829);
    Queue::fake([ChargeJob::class, DeliverPspWebhook::class, DeliverStoreNotification::class]);

    $product = Product::factory()->create(['slug' => 'edtech']);
    Plan::factory()->for($product)->create(['slug' => 'monthly']);

    // ---- PSP: 30 purchases in a forced mix ---------------------------------
    // 10 clean successes, 10 timeout-but-charged driven ONCE (so they can
    // only settle through the webhook or reconciliation), 5 declines,
    // 5 timeout-lost. Then 5 of the clean successes are refunded.
    $mix = array_merge(
        array_fill(0, 10, ['kind' => 'success', 'amount' => 1500]),
        array_fill(0, 10, ['kind' => 'timeout_charged', 'amount' => 1001]),
        array_fill(0, 5, ['kind' => 'declined', 'amount' => 1002]),
        array_fill(0, 5, ['kind' => 'timeout_lost', 'amount' => 1003]),
    );

    $byKind = ['success' => [], 'timeout_charged' => [], 'declined' => [], 'timeout_lost' => []];

    foreach ($mix as $i => $case) {
        $plan = Plan::factory()->for($product)->create(['slug' => "chaos-{$i}", 'amount_minor' => $case['amount']]);
        $intent = app(CreatePaymentIntent::class)->execute(User::factory()->create(), $plan);
        $byKind[$case['kind']][] = $intent->id;

        try {
            app(ChargeProcessor::class)->process($intent->id);
        } catch (PspUnavailable) {
            // one attempt only: ambiguity stays ambiguous
        }
    }

    $refunded = array_slice($byKind['success'], 0, 5);

    foreach ($refunded as $id) {
        $intent = PaymentIntent::query()->findOrFail($id);
        app(MockPsp::class)->refund((string) $intent->psp_reference, null, 'requested_by_customer');
    }

    // ---- Stores: 10 Apple + 10 Google subscriptions, each with a lifecycle --
    $actions = ['renew', 'cancel', 'fail_payment', 'refund', 'expire', 'renew', 'cancel', 'revoke', 'renew', 'expire'];
    $googleActions = ['renew', 'cancel', 'grace', 'on_hold', 'expire', 'renew', 'cancel', 'revoke', 'pause', 'recover'];
    $productId = StoreProductId::of('edtech', 'monthly');

    foreach ($actions as $i => $action) {
        $purchase = app(MockAppStore::class)->purchase($productId, User::factory()->create()->app_account_token);
        app(MockAppStore::class)->act($purchase->identifier, $action);
    }

    foreach ($googleActions as $i => $action) {
        $purchase = app(MockPlayStore::class)->purchase($productId, User::factory()->create()->app_account_token);
        app(MockPlayStore::class)->act($purchase->identifier, $action);
    }

    // ---- The wire: drop 20%, duplicate some, shuffle the rest --------------
    $pspDeliveries = Queue::pushed(DeliverPspWebhook::class)->map(fn (DeliverPspWebhook $job): array => $job->payload)->values()->all();
    $storeDeliveries = Queue::pushed(DeliverStoreNotification::class)->values()->all();

    shuffle($pspDeliveries);
    shuffle($storeDeliveries);

    $droppedPsp = [];
    $keptPsp = [];
    foreach ($pspDeliveries as $i => $payload) {
        $i % 5 === 0 ? $droppedPsp[] = $payload : $keptPsp[] = $payload;
    }

    $droppedStore = [];
    $keptStore = [];
    foreach ($storeDeliveries as $i => $job) {
        $i % 5 === 0 ? $droppedStore[] = $job : $keptStore[] = $job;
    }

    expect(count($droppedPsp))->toBeGreaterThan(0)
        ->and(count($droppedStore))->toBeGreaterThan(0);

    // Duplicate a third of what survives, then shuffle everything again.
    foreach ($keptPsp as $i => $payload) {
        if ($i % 3 === 0) {
            $keptPsp[] = $payload;
        }
    }
    foreach ($keptStore as $i => $job) {
        if ($i % 3 === 0) {
            $keptStore[] = $job;
        }
    }
    shuffle($keptPsp);
    shuffle($keptStore);

    Queue::fake([ChargeJob::class, DeliverPspWebhook::class, DeliverStoreNotification::class]);

    foreach ($keptPsp as $payload) {
        deliverPspWebhook($payload)->assertOk();
    }

    foreach ($keptStore as $job) {
        ($job->store === Store::Apple ? deliverAppleNotification($job->body) : deliverGoogleNotification($job->body))->assertOk();
    }

    // ---- What the drops cost us, computed independently of the reconciler --
    $droppedChargeEvents = array_filter($droppedPsp, static fn (array $p): bool => $p['type'] === 'charge.succeeded');
    $expectedMissedOutcomes = count(array_filter(
        $droppedChargeEvents,
        static fn (array $p): bool => in_array($p['data']['metadata']['payment_intent_id'], $byKind['timeout_charged'], true),
    ));
    $expectedMissedRefunds = count(array_filter($droppedPsp, static fn (array $p): bool => $p['type'] === 'charge.refunded'));

    expect(PaymentIntent::query()->whereIn('id', $byKind['timeout_charged'])->where('status', PaymentIntentStatus::Processing->value)->count())
        ->toBe($expectedMissedOutcomes);

    // Store drift: every store record whose local projection disagrees. A
    // store record with NO local row (both its notifications dropped) is
    // invisible to reconciliation by construction — see the end.
    $client = app(StoreClient::class);
    $expectedStoreDrift = 0;
    $invisible = [];

    foreach (StoreSubscription::query()->get() as $record) {
        $local = Subscription::query()->where('store_original_transaction_id', $record->identifier)->first();
        $snapshot = $client->fetchSubscription(Store::from($record->store), $record->identifier, CarbonImmutable::now());

        if ($local === null) {
            $invisible[] = $record;

            continue;
        }

        $expectedStatus = $local->status === SubscriptionStatus::Revoked && $snapshot?->status === SubscriptionStatus::Expired
            ? SubscriptionStatus::Revoked
            : $snapshot?->status;

        if ($local->status !== $expectedStatus || ! $local->current_period_end?->equalTo($snapshot->periodEnd->floorSecond())) {
            $expectedStoreDrift++;
        }
    }

    // Age every intent past the stuck threshold so the lost timeouts are swept.
    PaymentIntent::query()->update(['created_at' => CarbonImmutable::now()->subHour()]);

    // ---- One hourly run --------------------------------------------------
    $run = app(Reconciler::class)->run();

    // Money: every charged intent settled, every refund booked, nobody
    // double-charged, books exact.
    foreach (array_merge($byKind['success'], $byKind['timeout_charged']) as $id) {
        expect(PaymentIntent::query()->findOrFail($id)->status)->toBe(PaymentIntentStatus::Succeeded);
    }
    foreach ($byKind['declined'] as $id) {
        expect(PaymentIntent::query()->findOrFail($id)->status)->toBe(PaymentIntentStatus::Failed);
    }
    foreach ($byKind['timeout_lost'] as $id) {
        $intent = PaymentIntent::query()->findOrFail($id);
        expect($intent->status)->toBe(PaymentIntentStatus::Processing)
            ->and($intent->recovery_attempts)->toBe(1);
    }
    foreach ($refunded as $id) {
        $intent = PaymentIntent::query()->findOrFail($id);
        expect($intent->refunded_minor)->toBe(1500)
            ->and($intent->subscription->status)->toBe(SubscriptionStatus::Revoked);
    }

    Queue::assertPushed(ChargeJob::class, count($byKind['timeout_lost']));

    $ledger = app(Ledger::class);
    $charged = 10 * 1500 + 10 * 1001;
    $refundedTotal = 5 * 1500;

    expect($ledger->trialBalance()['balanced'])->toBeTrue()
        ->and($ledger->balance(LedgerAccount::PspCash, 'USD')->minor)->toBe($charged - $refundedTotal)
        ->and($ledger->balance(LedgerAccount::Revenue, 'USD')->minor)->toBe(-$charged)
        ->and($ledger->balance(LedgerAccount::Refunds, 'USD')->minor)->toBe($refundedTotal);

    /** @var list<object{n: int, s: int}> $transactions */
    $transactions = DB::table('ledger_entries')->selectRaw('transaction_id, COUNT(*) as n, SUM(amount_minor) as s')->groupBy('transaction_id')->get()->all();
    expect($transactions)->toHaveCount(20 + 5);
    foreach ($transactions as $transaction) {
        expect((int) $transaction->n)->toBe(2)->and((int) $transaction->s)->toBe(0);
    }

    // Entitlement: every visible store subscription now says what the store says.
    foreach (Subscription::query()->whereIn('store', [Store::Apple->value, Store::Google->value])->get() as $local) {
        $snapshot = $client->fetchSubscription($local->store, (string) $local->store_original_transaction_id, CarbonImmutable::now());
        $expectedStatus = $local->status === SubscriptionStatus::Revoked && $snapshot?->status === SubscriptionStatus::Expired
            ? SubscriptionStatus::Revoked
            : $snapshot?->status;

        expect($local->status)->toBe($expectedStatus)
            ->and($local->current_period_end?->equalTo($snapshot->periodEnd->floorSecond()))->toBeTrue();
    }

    // The report: exactly the discrepancies the drops caused, nothing unresolved.
    expect($run->fixed)->toBe($expectedMissedOutcomes + $expectedMissedRefunds + $expectedStoreDrift)
        ->and($run->unresolved)->toBe(0)
        ->and($run->findings['missed_charge_outcome'] ?? 0)->toBe($expectedMissedOutcomes)
        ->and($run->findings['missed_refund'] ?? 0)->toBe($expectedMissedRefunds)
        ->and($run->findings['store_drift'] ?? 0)->toBe($expectedStoreDrift)
        ->and($run->findings['redispatched_charge'] ?? 0)->toBe(count($byKind['timeout_lost']))
        ->and($run->fixed)->toBeGreaterThan(0);

    // Converged: the next hour finds nothing left to fix.
    expect(app(Reconciler::class)->run()->fixed)->toBe(0);

    // What reconciliation cannot see — a store purchase whose every
    // notification was dropped has no local row to compare — is the
    // client's "restore purchases" call's job, and it converges too.
    foreach ($invisible as $record) {
        $token = (string) $record->app_account_token;
        $user = User::query()->where('app_account_token', $token)->firstOrFail();

        $this->postJson("/v1/iap/{$record->store}/sync", ['user_id' => $user->id, 'identifier' => $record->identifier], [
            'Authorization' => 'Bearer test-billing-token',
        ])->assertOk();
    }

    expect(Subscription::query()->whereIn('store', [Store::Apple->value, Store::Google->value])->count())
        ->toBe(StoreSubscription::query()->count());
});
