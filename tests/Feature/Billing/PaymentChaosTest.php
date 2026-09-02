<?php

declare(strict_types=1);

use App\Domain\Billing\Enums\LedgerAccount;
use App\Domain\Billing\Enums\PaymentIntentStatus;
use App\Domain\Billing\Enums\SubscriptionStatus;
use App\Domain\Billing\Exceptions\PspUnavailable;
use App\Domain\Billing\Jobs\ChargeJob;
use App\Domain\Billing\Ledger\Ledger;
use App\Domain\Billing\Models\LedgerEntry;
use App\Domain\Billing\Models\PaymentIntent;
use App\Domain\Billing\Payments\ChargeProcessor;
use App\Domain\Billing\Payments\CreatePaymentIntent;
use App\Domain\Catalog\Models\Plan;
use App\Domain\Catalog\Models\Product;
use App\Domain\Identity\Models\User;
use App\MockPsp\Jobs\DeliverPspWebhook;
use App\MockPsp\Models\PspCharge;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/**
 * The Phase 3 deliverable: random timeouts, duplicated and reordered
 * webhooks — and a ledger that is EXACTLY correct at the end.
 *
 * Outcome mix is forced deterministically through the mock PSP's amount
 * convention; delivery chaos is seeded shuffling plus injected duplicates,
 * so a failure reproduces.
 */
it('keeps the ledger exactly correct through timeouts, duplicates and reordering', function (): void {
    mt_srand(chaosSeed(20260826));
    Queue::fake([ChargeJob::class, DeliverPspWebhook::class]);

    $product = Product::factory()->create();

    // 40 purchases: 16 clean successes, 8 declines, 8 timeout-but-charged
    // (ambiguous — must not double-charge), 8 timeout-lost (must stay
    // processing, no money moved).
    $mix = array_merge(
        array_fill(0, 16, ['kind' => 'success', 'amount' => 1500]),
        array_fill(0, 8, ['kind' => 'declined', 'amount' => 1002]),
        array_fill(0, 8, ['kind' => 'timeout_charged', 'amount' => 1001]),
        array_fill(0, 8, ['kind' => 'timeout_lost', 'amount' => 1003]),
    );

    $intentsByKind = ['success' => [], 'declined' => [], 'timeout_charged' => [], 'timeout_lost' => []];

    foreach ($mix as $i => $case) {
        $plan = Plan::factory()->for($product)->create([
            'slug' => "chaos-{$i}",
            'amount_minor' => $case['amount'],
        ]);

        $intent = app(CreatePaymentIntent::class)->execute(User::factory()->create(), $plan);
        $intentsByKind[$case['kind']][] = $intent->id;

        // Drive the charge attempts the way the queue would: retry on
        // ambiguity, same idempotency key every time.
        foreach (range(1, 5) as $attempt) {
            try {
                app(ChargeProcessor::class)->process($intent->id);
                break;
            } catch (PspUnavailable) {
                continue;
            }
        }
    }

    // Now the webhook storm: every delivery the PSP queued, with ~30%
    // duplicated, in a shuffled order — some land on intents already
    // settled synchronously, some settle intents themselves, every
    // duplicate and inversion must be absorbed.
    $deliveries = Queue::pushed(DeliverPspWebhook::class)
        ->map(fn (DeliverPspWebhook $job): array => $job->payload)
        ->all();

    $duplicated = $deliveries;
    foreach ($deliveries as $i => $payload) {
        if ($i % 3 === 0) {
            $duplicated[] = $payload;
        }
    }
    shuffle($duplicated);

    foreach ($duplicated as $payload) {
        deliverPspWebhook($payload)->assertOk();
    }

    $charged = array_merge($intentsByKind['success'], $intentsByKind['timeout_charged']);
    $expectedTotal = 16 * 1500 + 8 * 1001;

    // Every intent in a truthful terminal (or honestly-ambiguous) state.
    foreach ($charged as $id) {
        $intent = PaymentIntent::query()->findOrFail($id);
        expect($intent->status)->toBe(PaymentIntentStatus::Succeeded)
            ->and($intent->psp_reference)->toStartWith('ch_')
            ->and($intent->subscription->status)->toBe(SubscriptionStatus::Active);
    }

    foreach ($intentsByKind['declined'] as $id) {
        expect(PaymentIntent::query()->findOrFail($id)->status)->toBe(PaymentIntentStatus::Failed);
    }

    foreach ($intentsByKind['timeout_lost'] as $id) {
        expect(PaymentIntent::query()->findOrFail($id)->status)->toBe(PaymentIntentStatus::Processing);
    }

    // The PSP's books: exactly one charge per settled intent, none for the
    // lost timeouts — nobody was double-charged, provably.
    expect(PspCharge::query()->where('status', 'succeeded')->count())->toBe(count($charged))
        ->and(PspCharge::query()->count())->toBe(count($charged) + count($intentsByKind['declined']))
        ->and(PspCharge::query()->distinct()->count('idempotency_key'))->toBe(PspCharge::query()->count());

    // OUR books: exactly two lines per successful charge, balanced to zero,
    // and the cash position equals the sum of successful amounts to the
    // minor unit.
    $ledger = app(Ledger::class);
    $balance = $ledger->trialBalance();

    expect(LedgerEntry::query()->count())->toBe(2 * count($charged))
        ->and($balance['balanced'])->toBeTrue()
        ->and($ledger->balance(LedgerAccount::PspCash, 'USD')->minor)->toBe($expectedTotal)
        ->and($ledger->balance(LedgerAccount::Revenue, 'USD')->minor)->toBe(-$expectedTotal);

    // Per-transaction invariant: every transaction has exactly 2 lines
    // summing to zero — no orphaned or triplicated line anywhere.
    /** @var list<object{n: int, s: int}> $transactions */
    $transactions = DB::table('ledger_entries')
        ->selectRaw('transaction_id, COUNT(*) as n, SUM(amount_minor) as s')
        ->groupBy('transaction_id')
        ->get()
        ->all();

    expect($transactions)->toHaveCount(count($charged));

    foreach ($transactions as $transaction) {
        expect((int) $transaction->n)->toBe(2)
            ->and((int) $transaction->s)->toBe(0);
    }
})->group('chaos');
