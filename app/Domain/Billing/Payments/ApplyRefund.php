<?php

declare(strict_types=1);

namespace App\Domain\Billing\Payments;

use App\Domain\Billing\Enums\PaymentIntentStatus;
use App\Domain\Billing\Enums\SubscriptionStatus;
use App\Domain\Billing\Ledger\Ledger;
use App\Domain\Billing\Models\PaymentIntent;
use App\Domain\Billing\Money\Money;
use App\Domain\Billing\Subscriptions\SubscriptionStateMachine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The refund funnel — ApplyChargeOutcome's sibling. Webhook and
 * reconciliation both land here, under the intent's row lock, and the
 * ledger's unique key on the refund id is what makes a duplicate a no-op.
 *
 * Decision table:
 *  - unknown intent / currency or charge-id disagreement       -> logged, no-op
 *  - intent not yet succeeded                                   -> out_of_order: the refund
 *    outran the charge outcome; the caller retries later, and reconciliation
 *    is the backstop if the outcome never arrives
 *  - refund already booked (unique key)                         -> duplicate
 *  - otherwise: book the reversal, advance refunded_minor, and if
 *    every minor unit is now returned, revoke the subscription -> applied
 *
 * Revocation is by FULL refund only. A partial refund is a goodwill
 * gesture, not a rescission; treating it as one would punish exactly the
 * users support just tried to help.
 */
final readonly class ApplyRefund
{
    public function __construct(
        private Ledger $ledger,
        private SubscriptionStateMachine $subscriptions,
    ) {}

    public function apply(PspRefundEvent $event): string
    {
        return DB::transaction(function () use ($event): string {
            $intent = PaymentIntent::query()
                ->whereKey($event->paymentIntentId)
                ->lockForUpdate()
                ->first();

            if ($intent === null) {
                Log::warning('Refund references an unknown payment intent.', [
                    'event_id' => $event->eventId,
                    'refund_id' => $event->refundId,
                    'payment_intent_id' => $event->paymentIntentId,
                ]);

                return 'unknown_intent';
            }

            if ($event->currency !== $intent->currency) {
                Log::error('Refund currency disagrees with its charge.', [
                    'refund_id' => $event->refundId,
                    'payment_intent_id' => $intent->id,
                    'refund_currency' => $event->currency,
                    'intent_currency' => $intent->currency,
                ]);

                return 'amount_mismatch';
            }

            if ($intent->status !== PaymentIntentStatus::Succeeded) {
                if ($intent->status === PaymentIntentStatus::Failed) {
                    Log::error('Refund reported for a charge we recorded as failed.', [
                        'refund_id' => $event->refundId,
                        'payment_intent_id' => $intent->id,
                    ]);

                    return 'conflict';
                }

                return 'out_of_order';
            }

            if ($intent->psp_reference !== $event->chargeId) {
                Log::error('Refund names a different charge than the intent settled with.', [
                    'refund_id' => $event->refundId,
                    'payment_intent_id' => $intent->id,
                    'intent_charge' => $intent->psp_reference,
                    'refund_charge' => $event->chargeId,
                ]);

                return 'conflict';
            }

            $amount = Money::of($event->amountMinor, $event->currency);

            if (! $this->ledger->recordRefund($intent, $event->refundId, $amount, $event->occurredAt)) {
                return 'duplicate';
            }

            if ($intent->refunded_minor + $event->amountMinor > $intent->amount_minor) {
                // Booked as reported — the ledger records what the provider
                // did — but an over-refund is a provider-side anomaly worth
                // a loud log; the intent's projection is capped at the charge.
                Log::error('Refunds exceed the original charge.', [
                    'payment_intent_id' => $intent->id,
                    'charged' => $intent->amount_minor,
                    'refunded' => $intent->refunded_minor + $event->amountMinor,
                ]);
            }

            PaymentIntent::query()->whereKey($intent->id)->update([
                'refunded_minor' => min($intent->amount_minor, $intent->refunded_minor + $event->amountMinor),
                'updated_at' => $event->occurredAt,
            ]);

            $intent->refresh();

            if ($intent->isFullyRefunded()) {
                $this->revokeSubscription($intent, $event);
            }

            return 'applied';
        });
    }

    private function revokeSubscription(PaymentIntent $intent, PspRefundEvent $event): void
    {
        $subscription = $intent->subscription()->lockForUpdate()->firstOrFail();

        if ($subscription->status === SubscriptionStatus::Revoked) {
            return;
        }

        if (! $this->subscriptions->canTransition($subscription->status, SubscriptionStatus::Revoked)) {
            // Expired already: nothing to take away. The books still record
            // the refund; entitlement was gone before it.
            Log::info('Full refund on a subscription with no access to revoke.', [
                'subscription_id' => $subscription->id,
                'status' => $subscription->status->value,
                'refund_id' => $event->refundId,
            ]);

            return;
        }

        $this->subscriptions->revoke($subscription, $event->occurredAt);
    }
}
