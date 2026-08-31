<?php

declare(strict_types=1);

namespace App\Domain\Billing\Subscriptions;

use App\Domain\Billing\Enums\SubscriptionStatus;
use App\Domain\Billing\Exceptions\InvalidTransition;
use App\Domain\Billing\Models\Subscription;
use App\Domain\Outbox\DomainEvent;
use App\Domain\Outbox\Outbox;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * The explicit transition allow-list for subscriptions, and the only code
 * allowed to change a subscription's status. Everything else asks this
 * class, so the allow-list is a fact about the system rather than a comment
 * about it.
 *
 * Two write paths, one idiom:
 *
 *  - transition(): OUR policy. The allow-list is enforced; the write is a
 *    guarded UPDATE (WHERE status = expected-from), so under concurrency
 *    the row either moves exactly as decided or the write reports a
 *    conflict — never a lost update.
 *  - mirror(): the STORE's story (ADR-005). Apple and Google own those
 *    lifecycles; our row is a projection, so the allow-list is advisory
 *    (logged when violated — it means our model is missing a state) and the
 *    guard is ordering instead: WHERE last_event_at < the store's clock.
 *    That single predicate is both "reject stale transitions" and "no lost
 *    update", decided atomically by the database.
 *
 * Canceled -> Active is resubscribe-within-the-row (tech-debt #5: one live
 * subscription per (user, product) is enforced here, not by a partial
 * unique index MySQL doesn't have). Revoked is reachable from every live
 * state — refund, chargeback, store revocation — and nothing we initiate
 * leaves it.
 *
 * Every status change also publishes a domain event through the outbox
 * (Phase 5), in the same transaction as the guarded write — which is why
 * both write paths require the caller to already hold one; the Outbox
 * refuses to publish otherwise. Being the single writer makes this the
 * single publisher too: no lifecycle change can exist that the outbox
 * didn't see.
 */
final class SubscriptionStateMachine
{
    private const ALLOWED = [
        SubscriptionStatus::Incomplete->value => [SubscriptionStatus::Trialing, SubscriptionStatus::Active, SubscriptionStatus::Expired],
        SubscriptionStatus::Trialing->value => [SubscriptionStatus::Active, SubscriptionStatus::PastDue, SubscriptionStatus::Canceled, SubscriptionStatus::Expired, SubscriptionStatus::Revoked],
        SubscriptionStatus::Active->value => [SubscriptionStatus::PastDue, SubscriptionStatus::Paused, SubscriptionStatus::Canceled, SubscriptionStatus::Expired, SubscriptionStatus::Revoked],
        SubscriptionStatus::PastDue->value => [SubscriptionStatus::Active, SubscriptionStatus::Paused, SubscriptionStatus::Canceled, SubscriptionStatus::Expired, SubscriptionStatus::Revoked],
        SubscriptionStatus::Paused->value => [SubscriptionStatus::Active, SubscriptionStatus::PastDue, SubscriptionStatus::Canceled, SubscriptionStatus::Expired, SubscriptionStatus::Revoked],
        SubscriptionStatus::Canceled->value => [SubscriptionStatus::Active, SubscriptionStatus::Expired, SubscriptionStatus::Revoked],
        SubscriptionStatus::Expired->value => [],
        SubscriptionStatus::Revoked->value => [],
    ];

    public function __construct(private readonly Outbox $outbox) {}

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
            'last_event_at' => self::clock($at),
            'updated_at' => $at,
        ]);

        $changes += $this->sideEffects($subscription, $to, $at);

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

        $this->publishStatusChanged($subscription, $from, $at);
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

    /**
     * Revoke access now, from any live state. The paid period is left as it
     * was — history, not entitlement; the entitlement function never grants
     * on a revoked row regardless of period.
     */
    public function revoke(Subscription $subscription, CarbonImmutable $at): void
    {
        $this->transition($subscription, SubscriptionStatus::Revoked, $at);
    }

    /**
     * Mirror a store-authoritative snapshot (ADR-005). Returns true when the
     * row was written, false when the snapshot is stale — its clock is not
     * after the last one applied, so a newer truth is already in place and
     * this one carries nothing. Callers must hold the row lock.
     *
     * @param  array<string, mixed>  $attributes  the snapshot's columns (period, plan, ...)
     */
    public function mirror(
        Subscription $subscription,
        SubscriptionStatus $to,
        CarbonImmutable $at,
        array $attributes = [],
    ): bool {
        if ($subscription->last_event_at !== null && ! $at->isAfter($subscription->last_event_at)) {
            return false;
        }

        $from = $subscription->status;

        if ($from !== $to && ! $this->canTransition($from, $to)) {
            // The store did something our allow-list says cannot happen.
            // The store is right by definition; this log is a bug report
            // against our state model, not a reason to disagree with money.
            Log::warning('Store mirror applied a transition outside the allow-list.', [
                'subscription_id' => $subscription->id,
                'store' => $subscription->store->value,
                'from' => $from->value,
                'to' => $to->value,
            ]);
        }

        $changes = array_merge($attributes, [
            'status' => $to->value,
            'last_event_at' => self::clock($at),
            'updated_at' => $at,
        ]);

        $changes += $this->sideEffects($subscription, $to, $at);

        $updated = Subscription::query()
            ->whereKey($subscription->id)
            ->where('status', $from->value)
            ->where(function ($query) use ($at): void {
                $query->whereNull('last_event_at')->orWhere('last_event_at', '<', self::clock($at));
            })
            ->update($changes);

        if ($updated !== 1) {
            $subscription->refresh();

            if ($subscription->last_event_at !== null && ! $at->isAfter($subscription->last_event_at)) {
                return false; // someone applied a newer snapshot meanwhile
            }

            throw new InvalidTransition(
                "Subscription {$subscription->id}: lost the mirror race ({$from->value} -> {$to->value}); reload and re-decide."
            );
        }

        $subscription->refresh();

        // Only a status CHANGE is an event. Most mirrors are the hourly
        // re-sync confirming what we already knew; publishing those would be
        // an hourly heartbeat wearing a fact's name.
        if ($from !== $to) {
            $this->publishStatusChanged($subscription, $from, $at);
        }

        return true;
    }

    /**
     * last_event_at is a millisecond column; the query builder would
     * format a Carbon binding to whole seconds, so the watermark is bound
     * as an explicit string in both the write and the guard.
     */
    private static function clock(CarbonImmutable $at): string
    {
        return $at->utc()->format('Y-m-d H:i:s.v');
    }

    /**
     * The one publisher for subscription lifecycle events, fed by both write
     * paths after their guarded UPDATE succeeded — so a published event
     * always describes a write that really happened, exactly once per write.
     * The key is (row, new status, watermark): the same fact re-decided by a
     * redelivered job maps to the same key and the outbox's unique index
     * absorbs it. Payload is thin-plus (ADR-006).
     */
    private function publishStatusChanged(Subscription $subscription, SubscriptionStatus $from, CarbonImmutable $at): void
    {
        $to = $subscription->status;

        $this->outbox->publish(new DomainEvent(
            type: $to->domainEventType(),
            aggregateType: 'subscription',
            aggregateId: (string) $subscription->id,
            idempotencyKey: sprintf('subscription:%d:%s:%s', $subscription->id, $to->value, self::clock($at)),
            userId: $subscription->user_id,
            product: $subscription->product->slug,
            occurredAt: $at,
            payload: [
                'subscription_id' => $subscription->id,
                'plan_id' => $subscription->plan_id,
                'status' => $to->value,
                'previous_status' => $from->value,
                'store' => $subscription->store->value,
                'current_period_end' => $subscription->current_period_end?->toISOString(),
            ],
        ));
    }

    /**
     * Timestamp columns that follow the status, for both write paths.
     *
     * @return array<string, mixed>
     */
    private function sideEffects(Subscription $subscription, SubscriptionStatus $to, CarbonImmutable $at): array
    {
        return match ($to) {
            SubscriptionStatus::Canceled => ['canceled_at' => $subscription->canceled_at ?? $at],
            SubscriptionStatus::Revoked => ['revoked_at' => $subscription->revoked_at ?? $at],
            // (Re)entering a live state clears the exits. Period columns come
            // from the caller (it knows the plan or the store's snapshot).
            SubscriptionStatus::Active, SubscriptionStatus::Trialing,
            SubscriptionStatus::PastDue, SubscriptionStatus::Paused => ['canceled_at' => null, 'revoked_at' => null],
            default => [],
        };
    }
}
