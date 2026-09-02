<?php

declare(strict_types=1);

namespace App\Domain\Billing\Exceptions;

use DomainException;

/**
 * A non-terminal intent already exists for this subscription: some other
 * request's charge is pending, in flight, or ambiguous (timed out at the
 * PSP). Minting a second intent here is how one double-click becomes two
 * charges — found by the Phase 7 parallel-purchase test, which produced
 * five settled charges on one subscription before this guard existed.
 */
final class PaymentInProgress extends DomainException
{
    public function __construct(public readonly int $paymentIntentId, string $message)
    {
        parent::__construct($message);
    }
}
