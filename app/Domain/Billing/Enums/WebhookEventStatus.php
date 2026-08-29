<?php

declare(strict_types=1);

namespace App\Domain\Billing\Enums;

/**
 * Processing state of one persisted webhook delivery. The edge dispatches
 * on Pending; the job moves the row to exactly one of the other three.
 */
enum WebhookEventStatus: string
{
    /** Persisted, not yet applied. The reaper re-dispatches old ones. */
    case Pending = 'pending';

    /** Handled — including "handled as a duplicate" or "handled as stale". */
    case Processed = 'processed';

    /** Unusable: malformed, wrong environment, unknown identity. Kept for forensics. */
    case Discarded = 'discarded';

    /** Attempts exhausted on an exception. Reconciliation is the backstop. */
    case Failed = 'failed';

    public function isSettled(): bool
    {
        return $this !== self::Pending;
    }
}
