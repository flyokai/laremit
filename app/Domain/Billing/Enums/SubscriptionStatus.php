<?php

declare(strict_types=1);

namespace App\Domain\Billing\Enums;

/**
 * The states a subscription can be in. The transition allow-list and the
 * state machine that enforces it live in SubscriptionStateMachine; this
 * enum answers the one question every product asks: does this state grant
 * access right now?
 */
enum SubscriptionStatus: string
{
    /** Created, but the first payment has not succeeded yet. */
    case Incomplete = 'incomplete';

    case Trialing = 'trialing';

    case Active = 'active';

    /** Renewal failed; inside the dunning grace window. */
    case PastDue = 'past_due';

    case Paused = 'paused';

    /** Cancelled by the user; access may run to the end of the paid period. */
    case Canceled = 'canceled';

    case Expired = 'expired';

    /**
     * Refund, chargeback or store revocation. Access removed immediately,
     * from any state — the one transition that bypasses everything.
     */
    case Revoked = 'revoked';

    /**
     * Whether the state itself grants access.
     *
     * PastDue deliberately grants access: revoking during dunning costs more in
     * churn than the few days of service given away, and every PSP retries a
     * failed renewal for days before giving up. Canceled does not grant access
     * on its own — a cancelled subscription still inside its paid period is
     * granted access by current_period_end, not by status, so that decision
     * stays in one place (the entitlement function).
     */
    public function grantsAccess(): bool
    {
        return match ($this) {
            self::Trialing, self::Active, self::PastDue => true,
            self::Incomplete, self::Paused, self::Canceled, self::Expired, self::Revoked => false,
        };
    }

    /**
     * No transition WE initiate leaves a terminal state: a new purchase on
     * the PSP path starts a new row. Store-authoritative mirrors are the
     * exception (SubscriptionStateMachine::mirror) — Apple reuses one
     * originalTransactionId across resubscribes, so the same row must be
     * able to come back when the store says it did.
     */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Expired, self::Revoked => true,
            default => false,
        };
    }
}
