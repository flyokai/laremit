<?php

declare(strict_types=1);

namespace App\Domain\Billing\Reconciliation\Sweeps;

use App\Domain\Billing\Contracts\PspClient;
use App\Domain\Billing\Enums\PaymentIntentStatus;
use App\Domain\Billing\Jobs\ChargeJob;
use App\Domain\Billing\Models\PaymentIntent;
use App\Domain\Billing\Payments\ApplyChargeOutcome;
use App\Domain\Billing\Payments\PspEvent;
use App\Domain\Billing\Reconciliation\ReconciliationReport;
use App\Domain\Billing\Reconciliation\Sweep;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

/**
 * Ours -> theirs. Every intent still pending/processing past the point
 * where a healthy charge would have resolved is asked about, by our own
 * idempotency key — the one identifier both sides had before any money
 * moved. Two answers, two very different recoveries:
 *
 *  - the PSP has a charge under that key: the ambiguity is over, and we
 *    can settle it through the funnel. (This is where Phase 3's
 *    "never guess failed" pays off: the intent was left truthfully
 *    unknown, and now it is truthfully known.)
 *  - the PSP has nothing: the call never landed. Re-dispatching the
 *    ChargeJob is a clean do-over — the same key means it still cannot
 *    double-charge — bounded by recovery_attempts so a permanently broken
 *    intent escalates instead of being retried every hour forever.
 */
final readonly class StuckIntentSweep implements Sweep
{
    public function __construct(
        private PspClient $psp,
        private ApplyChargeOutcome $outcomes,
    ) {}

    public function sweep(ReconciliationReport $report, CarbonImmutable $now, CarbonImmutable $windowStart): void
    {
        $threshold = $now->subMinutes((int) config('billing.reconciliation.stuck_after_minutes'));
        $maxAttempts = (int) config('billing.reconciliation.max_recovery_attempts');

        PaymentIntent::query()
            ->whereIn('status', [PaymentIntentStatus::Pending->value, PaymentIntentStatus::Processing->value])
            ->where('created_at', '<', $threshold)
            ->chunkById(100, function (Collection $intents) use ($report, $now, $maxAttempts): void {
                /** @var PaymentIntent $intent */
                foreach ($intents as $intent) {
                    $report->scanned('stuck_intents');

                    $remote = $this->psp->findCharge($intent->psp_idempotency_key);

                    if ($remote !== null) {
                        $result = $this->outcomes->apply(PspEvent::fromRemote($remote, $intent->id));

                        $result === 'applied'
                            ? $report->fixed('settled_from_provider', ['payment_intent_id' => $intent->id, 'charge_id' => $remote->chargeId])
                            : $report->unresolved("stuck_{$result}", ['payment_intent_id' => $intent->id]);

                        continue;
                    }

                    if ($intent->recovery_attempts >= $maxAttempts) {
                        $report->unresolved('stuck_intent', [
                            'payment_intent_id' => $intent->id,
                            'recovery_attempts' => $intent->recovery_attempts,
                            'age_minutes' => (int) $intent->created_at?->diffInMinutes($now),
                        ]);

                        continue;
                    }

                    PaymentIntent::query()->whereKey($intent->id)->update([
                        'recovery_attempts' => $intent->recovery_attempts + 1,
                        'last_recovered_at' => $now,
                    ]);

                    ChargeJob::dispatch($intent->id);

                    $report->noted('redispatched_charge', ['payment_intent_id' => $intent->id, 'attempt' => $intent->recovery_attempts + 1]);
                }
            });
    }
}
