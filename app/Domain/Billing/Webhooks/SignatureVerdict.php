<?php

declare(strict_types=1);

namespace App\Domain\Billing\Webhooks;

enum SignatureVerdict
{
    case Valid;

    /** No signature header at all. */
    case Missing;

    /** Header present but not in the t=…,v1=… shape. */
    case Malformed;

    /** Signature verifies, but the signed timestamp is outside tolerance: a replay. */
    case Stale;

    /** Signature does not verify: forged, wrong secret, or altered bytes. */
    case Invalid;
}
