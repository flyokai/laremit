<?php

declare(strict_types=1);

namespace App\Domain\Billing\Payments;

use App\Domain\Billing\Enums\PaymentIntentStatus;
use App\Domain\Billing\Enums\SubscriptionStatus;
use App\Domain\Billing\Exceptions\AlreadySubscribed;
use App\Domain\Billing\Exceptions\PaymentInProgress;
use App\Domain\Billing\Jobs\ChargeJob;
use App\Domain\Billing\Models\PaymentIntent;
use App\Domain\Billing\Models\Subscription;
use App\Domain\Billing\Money\Money;
use App\Domain\Catalog\Models\Plan;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Start a paid subscription: find or create the (user, product)
 * subscription row, mint the intent with its lifetime PSP idempotency key,
 * and queue the charge — all in one transaction, with the job dispatched
 * afterCommit so a rollback cannot leave a charge in flight for an intent
 * that never existed.
 *
 * The one-live-subscription-per-(user, product) rule lives here (with the
 * state machine): a granting subscription refuses a second purchase; an
 * incomplete or canceled row is reused, which is what makes
 * resubscribe-after-cancel a payment, not a new identity.
 */
final readonly class CreatePaymentIntent
{
    public function execute(User $user, Plan $plan): PaymentIntent
    {
        return DB::transaction(function () use ($user, $plan): PaymentIntent {
            // The serialization anchor. The subscription row this purchase
            // is about may not exist yet, and a FOR UPDATE that matches no
            // row gives rivals nothing they must wait behind — under load,
            // two first-purchases both saw "no subscription" and BOTH
            // charged (caught by the Phase 7 parallel test: two active rows,
            // two charges, one user). The user row always exists, so every
            // purchase for this user queues here in a total order.
            User::query()->whereKey($user->id)->lockForUpdate()->first();

            $subscription = Subscription::query()
                ->where('user_id', $user->id)
                ->where('product_id', $plan->product_id)
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            // Same semantics as the entitlement function: any CURRENT access
            // blocks a new charge — including canceled-but-still-in-period,
            // where charging again would make the user pay twice for the
            // overlap. Once the paid period lapses, the row is rechargeable.
            $blocking = $subscription !== null
                && ($subscription->status->grantsAccess()
                    || ($subscription->status === SubscriptionStatus::Canceled && $subscription->withinCurrentPeriod()));

            if ($blocking) {
                throw new AlreadySubscribed(
                    "User {$user->id} still has access to product {$plan->product_id}."
                );
            }

            // A rechargeable subscription may still carry an in-flight
            // intent: another request created it and its charge is pending,
            // processing, or ambiguous. The row lock above makes this check
            // race-free — a rival either waits behind us or sees our intent.
            // Without it, 20 parallel purchases with distinct idempotency
            // keys produced ONE subscription and FIVE settled charges.
            if ($subscription !== null) {
                $inFlight = PaymentIntent::query()
                    ->where('subscription_id', $subscription->id)
                    ->whereIn('status', [PaymentIntentStatus::Pending, PaymentIntentStatus::Processing])
                    ->orderByDesc('id')
                    ->first();

                if ($inFlight !== null) {
                    throw new PaymentInProgress(
                        $inFlight->id,
                        "Payment intent {$inFlight->id} for subscription {$subscription->id} is still {$inFlight->status->value}."
                    );
                }
            }

            if ($subscription === null || $subscription->status === SubscriptionStatus::Expired) {
                $subscription = Subscription::query()->create([
                    'user_id' => $user->id,
                    'product_id' => $plan->product_id,
                    'plan_id' => $plan->id,
                    'status' => SubscriptionStatus::Incomplete,
                ]);
            } elseif ($subscription->plan_id !== $plan->id) {
                // Re-purchase on a different plan: the retained row follows
                // the money.
                $subscription->update(['plan_id' => $plan->id]);
            }

            $intent = PaymentIntent::query()->create([
                'user_id' => $user->id,
                'subscription_id' => $subscription->id,
                'plan_id' => $plan->id,
                'purpose' => 'initial',
                'amount' => Money::of($plan->amount_minor, $plan->currency),
                'status' => PaymentIntentStatus::Pending,
                'psp_idempotency_key' => (string) Str::ulid(),
            ]);

            ChargeJob::dispatch($intent->id)->afterCommit();

            return $intent;
        });
    }
}
