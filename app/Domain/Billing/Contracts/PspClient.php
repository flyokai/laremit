<?php

declare(strict_types=1);

namespace App\Domain\Billing\Contracts;

use App\Domain\Billing\Exceptions\PspUnavailable;
use App\Domain\Billing\Money\Money;
use App\Domain\Billing\Psp\ChargeResult;
use App\Domain\Billing\Psp\RemoteCharge;
use Carbon\CarbonImmutable;

/**
 * The outbound payment provider boundary (ADR-004, layer 2).
 *
 * Contract the caller must honour: a PspUnavailable throw is AMBIGUOUS —
 * the charge may exist. The only safe follow-ups are retrying with the same
 * idempotency key or waiting for the webhook. A definitive decline comes
 * back as a ChargeResult, never as an exception.
 *
 * The two read methods exist for reconciliation: they let us ask the
 * provider what it believes, in both directions, when webhooks did not.
 */
interface PspClient
{
    /**
     * @param  array<string, mixed>  $metadata  echoed back in webhooks; carries payment_intent_id
     *
     * @throws PspUnavailable
     */
    public function charge(string $idempotencyKey, Money $amount, array $metadata): ChargeResult;

    /**
     * Every charge the provider created at or after $since (theirs -> ours).
     *
     * @return list<RemoteCharge>
     *
     * @throws PspUnavailable
     */
    public function listCharges(CarbonImmutable $since): array;

    /**
     * The charge created under OUR idempotency key, if any (ours -> theirs).
     * Null is a definitive "never happened", which is what makes a
     * re-dispatch safe.
     *
     * @throws PspUnavailable
     */
    public function findCharge(string $idempotencyKey): ?RemoteCharge;
}
