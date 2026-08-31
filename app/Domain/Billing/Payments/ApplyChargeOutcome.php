<?php

declare(strict_types=1);

namespace App\Domain\Billing\Payments;

use App\Domain\Billing\Enums\PaymentIntentStatus;
use App\Domain\Billing\Enums\SubscriptionStatus;
use App\Domain\Billing\Ledger\Ledger;
use App\Domain\Billing\Models\PaymentIntent;
use App\Domain\Billing\Subscriptions\SubscriptionStateMachine;
use App\Domain\Outbox\DomainEvent;
use App\Domain\Outbox\Outbox;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The single funnel every charge outcome flows through — synchronous API
 * result and webhook alike — and the reason duplicates and reordering are
 * survivable at all: idempotency is decided HERE, once, under a row lock,
 * not per delivery path.
 *
 * The decision table:
 *  - intent already terminal with the same charge + same outcome  -> duplicate (no-op)
 *  - intent already terminal, event disagrees                     -> conflict (log loudly, change nothing)
 *  - amount/currency mismatch with the intent                     -> mismatch (log loudly, change nothing)
 *  - otherwise: transition the intent, book the ledger, move the
 *    subscription — atomically                                    -> applied
 *
 * "Terminal wins" is the conflict policy: a late contradictory delivery
 * cannot un-happen money that already moved; disagreement is a fact for
 * Phase 4's reconciliation, not something to merge in-line.
 */
final readonly class ApplyChargeOutcome
{
    public function __construct(
        private Ledger $ledger,
        private SubscriptionStateMachine $subscriptions,
        private Outbox $outbox,
    ) {}

    public function apply(PspEvent $event): string
    {
        return DB::transaction(function () use ($event): string {
            $intent = PaymentIntent::query()
                ->whereKey($event->paymentIntentId)
                ->lockForUpdate()
                ->first();

            if ($intent === null) {
                Log::warning('PSP event references an unknown payment intent.', [
                    'event_id' => $event->eventId,
                    'payment_intent_id' => $event->paymentIntentId,
                ]);

                return 'unknown_intent';
            }

            if ($event->amountMinor !== $intent->amount_minor || $event->currency !== $intent->currency) {
                Log::error('PSP event amount disagrees with its payment intent.', [
                    'event_id' => $event->eventId,
                    'payment_intent_id' => $intent->id,
                    'event_amount' => "{$event->currency} {$event->amountMinor}",
                    'intent_amount' => "{$intent->currency} {$intent->amount_minor}",
                ]);

                return 'amount_mismatch';
            }

            if ($intent->status->isTerminal()) {
                $consistent = $intent->psp_reference === $event->chargeId
                    && ($intent->status === PaymentIntentStatus::Succeeded) === $event->succeeded();

                if ($consistent) {
                    return 'duplicate';
                }

                Log::error('Conflicting PSP event for a settled payment intent; terminal state wins.', [
                    'event_id' => $event->eventId,
                    'payment_intent_id' => $intent->id,
                    'intent_status' => $intent->status->value,
                    'intent_charge' => $intent->psp_reference,
                    'event_type' => $event->type,
                    'event_charge' => $event->chargeId,
                ]);

                return 'conflict';
            }

            $to = $event->succeeded() ? PaymentIntentStatus::Succeeded : PaymentIntentStatus::Failed;

            // Guarded terminal write: the row lock serializes deliveries, the
            // status predicate makes the transition single-shot even if it
            // didn't.
            $updated = PaymentIntent::query()
                ->whereKey($intent->id)
                ->where('status', $intent->status->value)
                ->update([
                    'status' => $to->value,
                    'psp_reference' => $event->chargeId,
                    'last_error' => $event->succeeded() ? null : ($event->declineCode ?? 'card_declined'),
                    'updated_at' => $event->occurredAt,
                ]);

            if ($updated !== 1) {
                return 'conflict';
            }

            $intent->refresh();

            // Same transaction as the settle itself (the outbox insists):
            // the event exists if and only if the intent really moved, and
            // the single-shot guard above makes it one event per intent, ever.
            $this->publishSettled($intent, $event);

            if ($event->succeeded()) {
                // The ledger's unique keys make this safe even if every guard
                // above were deleted; with the guards it records exactly once.
                $this->ledger->recordCharge($intent, $event->chargeId, $event->occurredAt);

                $this->activateSubscription($intent, $event);
            }

            return 'applied';
        });
    }

    private function publishSettled(PaymentIntent $intent, PspEvent $event): void
    {
        $this->outbox->publish(new DomainEvent(
            type: $event->succeeded() ? 'billing.payment.succeeded' : 'billing.payment.failed',
            aggregateType: 'payment_intent',
            aggregateId: (string) $intent->id,
            // "This intent settled" is one fact regardless of which way it
            // went — the terminal-wins guard means it can only go one way.
            idempotencyKey: "payment:{$intent->id}:settled",
            userId: $intent->user_id,
            product: $intent->plan->product->slug,
            occurredAt: $event->occurredAt,
            payload: [
                'payment_intent_id' => $intent->id,
                'subscription_id' => $intent->subscription_id,
                'plan_id' => $intent->plan_id,
                'amount_minor' => $intent->amount_minor,
                'currency' => $intent->currency,
                'charge_id' => $event->chargeId,
                'decline_code' => $event->succeeded() ? null : $intent->last_error,
            ],
        ));
    }

    private function activateSubscription(PaymentIntent $intent, PspEvent $event): void
    {
        $subscription = $intent->subscription()->lockForUpdate()->firstOrFail();

        if ($subscription->status === SubscriptionStatus::Active) {
            return; // another outcome path already activated it
        }

        $plan = $intent->plan;

        $this->subscriptions->activate(
            $subscription,
            $event->occurredAt,
            $plan->interval->advance($event->occurredAt, $plan->interval_count),
            $event->occurredAt,
        );
    }
}
