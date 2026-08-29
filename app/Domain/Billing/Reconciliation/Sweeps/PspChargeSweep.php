<?php

declare(strict_types=1);

namespace App\Domain\Billing\Reconciliation\Sweeps;

use App\Domain\Billing\Contracts\PspClient;
use App\Domain\Billing\Enums\LedgerAccount;
use App\Domain\Billing\Enums\PaymentIntentStatus;
use App\Domain\Billing\Models\LedgerEntry;
use App\Domain\Billing\Models\PaymentIntent;
use App\Domain\Billing\Payments\ApplyChargeOutcome;
use App\Domain\Billing\Payments\ApplyRefund;
use App\Domain\Billing\Payments\PspEvent;
use App\Domain\Billing\Payments\PspRefundEvent;
use App\Domain\Billing\Psp\RemoteCharge;
use App\Domain\Billing\Reconciliation\ReconciliationReport;
use App\Domain\Billing\Reconciliation\Sweep;
use Carbon\CarbonImmutable;

/**
 * Theirs -> ours. Walk every charge the PSP created in the window and
 * hold it against our intent. Three shapes of disagreement:
 *
 *  - they have it, we never settled it: the outcome webhook was lost.
 *    Feed the provider's record through the SAME funnel the webhook would
 *    have used — reconciliation is just a third road into it — and count
 *    a fix.
 *  - they have it, we don't: money moved for something we cannot find.
 *    Nothing here can fix that; it pages.
 *  - both settled, differently: "terminal wins" (ADR-004) forbids
 *    overwriting a settled intent from a delivery, and that includes this
 *    one. Pages, with both stories in the log, for a human and an
 *    adjustment transaction.
 *
 * Refunds ride along: any refund the provider lists that is not in our
 * books goes through the refund funnel.
 */
final readonly class PspChargeSweep implements Sweep
{
    public function __construct(
        private PspClient $psp,
        private ApplyChargeOutcome $outcomes,
        private ApplyRefund $refunds,
    ) {}

    public function sweep(ReconciliationReport $report, CarbonImmutable $now, CarbonImmutable $windowStart): void
    {
        foreach ($this->psp->listCharges($windowStart) as $remote) {
            $report->scanned('psp_charges');

            $intent = $this->locate($remote);

            if ($intent === null) {
                $report->unresolved('orphan_charge', ['charge_id' => $remote->chargeId, 'idempotency_key' => $remote->idempotencyKey]);

                continue;
            }

            if (! $intent->status->isTerminal()) {
                $result = $this->outcomes->apply(PspEvent::fromRemote($remote, $intent->id));

                if ($result !== 'applied') {
                    $report->unresolved("charge_{$result}", ['payment_intent_id' => $intent->id, 'charge_id' => $remote->chargeId]);

                    continue;
                }

                $report->fixed('missed_charge_outcome', ['payment_intent_id' => $intent->id, 'charge_id' => $remote->chargeId]);
                $intent->refresh();
            } elseif (! $this->agree($intent, $remote, $report)) {
                continue;
            }

            if ($intent->status === PaymentIntentStatus::Succeeded) {
                $this->reconcileRefunds($intent, $remote, $report);
            }
        }
    }

    private function locate(RemoteCharge $remote): ?PaymentIntent
    {
        $intent = PaymentIntent::query()->where('psp_idempotency_key', $remote->idempotencyKey)->first();

        if ($intent === null && $remote->paymentIntentId !== null) {
            $intent = PaymentIntent::query()->find($remote->paymentIntentId);
        }

        return $intent;
    }

    private function agree(PaymentIntent $intent, RemoteCharge $remote, ReconciliationReport $report): bool
    {
        $context = ['payment_intent_id' => $intent->id, 'charge_id' => $remote->chargeId];

        if (($intent->status === PaymentIntentStatus::Succeeded) !== $remote->succeeded) {
            $report->unresolved('status_drift', $context + ['local' => $intent->status->value, 'remote' => $remote->succeeded ? 'succeeded' : 'failed']);

            return false;
        }

        if ($intent->amount_minor !== $remote->amountMinor || $intent->currency !== $remote->currency) {
            $report->unresolved('amount_drift', $context + ['local' => "{$intent->currency} {$intent->amount_minor}", 'remote' => "{$remote->currency} {$remote->amountMinor}"]);

            return false;
        }

        if ($intent->psp_reference !== $remote->chargeId) {
            $report->unresolved('charge_id_drift', $context + ['local' => $intent->psp_reference]);

            return false;
        }

        return true;
    }

    private function reconcileRefunds(PaymentIntent $intent, RemoteCharge $remote, ReconciliationReport $report): void
    {
        foreach ($remote->refunds as $refund) {
            $report->scanned('psp_refunds');

            $booked = LedgerEntry::query()
                ->where('idempotency_key', "refund:{$refund->refundId}:".LedgerAccount::PspCash->value)
                ->exists();

            if ($booked) {
                continue;
            }

            $result = $this->refunds->apply(PspRefundEvent::fromRemote($remote, $refund, $intent->id));

            match ($result) {
                'applied' => $report->fixed('missed_refund', ['payment_intent_id' => $intent->id, 'refund_id' => $refund->refundId]),
                'duplicate' => null,
                default => $report->unresolved("refund_{$result}", ['payment_intent_id' => $intent->id, 'refund_id' => $refund->refundId]),
            };
        }
    }
}
