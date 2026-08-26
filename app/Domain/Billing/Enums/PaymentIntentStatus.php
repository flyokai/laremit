<?php

declare(strict_types=1);

namespace App\Domain\Billing\Enums;

/**
 * Lifecycle of a payment intent, with its transition allow-list.
 *
 * Pending may jump straight to a terminal state: webhooks can outrun the
 * ChargeJob (the mock PSP fires them deliberately early/late), and a
 * webhook-first arrival is normal operation, not an anomaly.
 */
enum PaymentIntentStatus: string
{
    case Pending = 'pending';

    /** ChargeJob has claimed it; a PSP call is (or was) in flight. */
    case Processing = 'processing';

    case Succeeded = 'succeeded';

    case Failed = 'failed';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Succeeded, self::Failed => true,
            default => false,
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, match ($this) {
            self::Pending => [self::Processing, self::Succeeded, self::Failed],
            self::Processing => [self::Succeeded, self::Failed],
            self::Succeeded, self::Failed => [],
        }, true);
    }
}
