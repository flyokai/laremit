<?php

declare(strict_types=1);

namespace App\Domain\Billing\Contracts;

use App\Domain\Billing\Exceptions\PspUnavailable;
use App\Domain\Billing\Money\Money;
use App\Domain\Billing\Psp\ChargeResult;

/**
 * The outbound payment provider boundary (ADR-004, layer 2).
 *
 * Contract the caller must honour: a PspUnavailable throw is AMBIGUOUS —
 * the charge may exist. The only safe follow-ups are retrying with the same
 * idempotency key or waiting for the webhook. A definitive decline comes
 * back as a ChargeResult, never as an exception.
 */
interface PspClient
{
    /**
     * @param  array<string, mixed>  $metadata  echoed back in webhooks; carries payment_intent_id
     *
     * @throws PspUnavailable
     */
    public function charge(string $idempotencyKey, Money $amount, array $metadata): ChargeResult;
}
