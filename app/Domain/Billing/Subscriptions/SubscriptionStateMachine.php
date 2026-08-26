<?php

declare(strict_types=1);

namespace App\Domain\Billing\Subscriptions;

use App\Domain\Billing\Enums\SubscriptionStatus;
use App\Domain\Billing\Exceptions\InvalidTransition;
use App\Domain\Billing\Models\Subscription;
use Carbon\CarbonImmutable;

/**
 * The explicit transition allow-list for subscriptions, and the only code
 * allowed to change a subscription's status. Everything else asks this
 * class, so the allow-list is a fact about the system rather than a comment
 * about it.
 *
 * Writes are guarded UPDATEs (WHERE status = expected-from): under
 * concurrency the row either moves exactly as decided or the write reports
 * a conflict — never a lost update, never a a-overwrites-b.
 *
 * Canceled -> Active is resubscribe-within-the-row (pays off tech-debt #5:
 * one live subscription per (user, product) is enforced here, not by a
 * partial unique index MySQL doesn't have).
 */
final class SubscriptionStateMachine
{
    private const ALLOWED = [
        SubscriptionStatus::Incomplete->value => [SubscriptionStatus::Trialing, SubscriptionStatus::Active, SubscriptionStatus::Expired],
        SubscriptionStatus::Trialing->value => [SubscriptionStatus::Active, SubscriptionStatus::PastDue, SubscriptionStatus::Canceled, SubscriptionStatus::Expired],
        SubscriptionStatus::Active->value => [SubscriptionStatus::PastDue, SubscriptionStatus::Paused, SubscriptionStatus::Canceled, SubscriptionStatus::Expired],
        SubscriptionStatus::PastDue->value => [SubscriptionStatus::Active, SubscriptionStatus::Canceled, SubscriptionStatus::Expired],
        SubscriptionStatus::Paused->value => [SubscriptionStatus::Active, SubscriptionStatus::Canceled, SubscriptionStatus::Expired],
        SubscriptionStatus::Canceled->value => [SubscriptionStatus::Active, SubscriptionStatus::Expired],
        SubscriptionStatus::Expired->value => [],
    ];

    public function canTransition(SubscriptionStatus $from, SubscriptionStatus $to): bool
    {
        return in_array($to, self::ALLOWED[$from->value], true);
    }

    /**
     * Apply a transition with its side effects, guarded against concurrent
     * movement. $subscription is refreshed to the written state.
     *
     * @param  array<string, mixed>  $attributes  extra columns set atomically with the status
     */
    public function transition(
        Subscription $subscription,
        SubscriptionStatus $to,
        CarbonImmutable $at,
        array $attributes = [],
    ): void {
        $from = $subscription->status;

        if (! $this->canTransition($from, $to)) {
            throw new InvalidTransition(
                "Subscription {$subscription->id}: {$from->value} -> {$to->value} is not in the allow-list."
            );
        }

        $changes = array_merge($attributes, [
            'status' => $to->value,
            'last_event_at' => $at,
            'updated_at' => $at,
        ]);

        $changes += match ($to) {
            SubscriptionStatus::Canceled => ['canceled_at' => $at],
            // Reactivation clears the cancellation; period columns come from
            // the caller (it knows the plan).
            SubscriptionStatus::Active => ['canceled_at' => null],
            default => [],
        };

        $updated = Subscription::query()
            ->whereKey($subscription->id)
            ->where('status', $from->value)
            ->update($changes);

        if ($updated !== 1) {
            throw new InvalidTransition(
                "Subscription {$subscription->id}: lost the {$from->value} -> {$to->value} race; reload and re-decide."
            );
        }

        $subscription->refresh();
    }

    /**
     * Activate for a paid period: the one composite operation payments need.
     * Sets the period boundaries computed by the caller from the plan.
     */
    public function activate(
        Subscription $subscription,
        CarbonImmutable $periodStart,
        CarbonImmutable $periodEnd,
        CarbonImmutable $at,
    ): void {
        $this->transition($subscription, SubscriptionStatus::Active, $at, [
            'current_period_start' => $periodStart,
            'current_period_end' => $periodEnd,
        ]);
    }
}
