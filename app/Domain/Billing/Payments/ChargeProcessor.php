<?php

declare(strict_types=1);

namespace App\Domain\Billing\Payments;

use App\Domain\Billing\Contracts\PspClient;
use App\Domain\Billing\Enums\PaymentIntentStatus;
use App\Domain\Billing\Models\PaymentIntent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * One charge attempt against the PSP. Called by ChargeJob, possibly many
 * times for one intent — every attempt reuses the intent's single PSP
 * idempotency key, which is what makes "retry after ambiguity" safe.
 *
 * A PspUnavailable/PspTimedOut throw deliberately escapes: the queue owns
 * the retry schedule. The intent stays `processing` through the ambiguity —
 * the truthful state, resolvable by a later attempt's idempotent replay or
 * by the webhook, whichever lands first.
 */
final readonly class ChargeProcessor
{
    public function __construct(
        private PspClient $psp,
        private ApplyChargeOutcome $outcomes,
    ) {}

    public function process(int $paymentIntentId): void
    {
        $intent = PaymentIntent::query()->find($paymentIntentId);

        if ($intent === null || $intent->status->isTerminal()) {
            return; // settled by a webhook (or gone) before this attempt ran
        }

        if ($intent->status === PaymentIntentStatus::Pending) {
            // Guarded claim; losing it means another path moved the intent,
            // and processing->processing on retry is simply not a change.
            PaymentIntent::query()
                ->whereKey($intent->id)
                ->where('status', PaymentIntentStatus::Pending->value)
                ->update(['status' => PaymentIntentStatus::Processing->value]);

            $intent->refresh();

            if ($intent->status->isTerminal()) {
                return;
            }
        }

        $result = $this->psp->charge(
            $intent->psp_idempotency_key,
            $intent->amount,
            [
                'payment_intent_id' => $intent->id,
                'user_id' => $intent->user_id,
            ],
        );

        // Normalize the synchronous answer into the same event shape a
        // webhook produces, so there is exactly one application path.
        $this->outcomes->apply(new PspEvent(
            eventId: 'sync_'.Str::ulid(),
            type: $result->succeeded ? 'charge.succeeded' : 'charge.failed',
            chargeId: $result->chargeId,
            paymentIntentId: $intent->id,
            amountMinor: $intent->amount_minor,
            currency: $intent->currency,
            declineCode: $result->declineCode,
            occurredAt: CarbonImmutable::now(),
        ));
    }
}
