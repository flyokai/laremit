<?php

declare(strict_types=1);

namespace App\MockPsp;

use App\Domain\Billing\Contracts\PspClient;
use App\Domain\Billing\Exceptions\PspTimedOut;
use App\Domain\Billing\Exceptions\PspUnavailable;
use App\Domain\Billing\Money\Money;
use App\Domain\Billing\Psp\ChargeResult;
use RuntimeException;

/**
 * The mock PSP invoked in-process: identical decision logic to the HTTP
 * path — including timeouts that secretly charge — but clock-free, which is
 * what lets the chaos test run its hundred ambiguous outcomes in
 * milliseconds instead of sleeping through them.
 */
final readonly class LoopbackPspClient implements PspClient
{
    public function __construct(private MockPsp $psp) {}

    public function charge(string $idempotencyKey, Money $amount, array $metadata): ChargeResult
    {
        try {
            $response = $this->psp->charge($idempotencyKey, $amount->minor, $amount->currency, $metadata);
        } catch (MockPspTimedOut $e) {
            throw new PspTimedOut('Mock PSP timed out (loopback).', previous: $e);
        }

        $chargeId = $response->body['charge_id'] ?? null;

        if ($response->status === 201 && is_string($chargeId)) {
            return ChargeResult::succeeded($chargeId);
        }

        if ($response->status === 402 && is_string($chargeId)) {
            $declineCode = $response->body['decline_code'] ?? null;

            return ChargeResult::declined($chargeId, is_string($declineCode) ? $declineCode : 'card_declined');
        }

        if ($response->status === 409) {
            throw new RuntimeException("PSP reports idempotency key reuse for [{$idempotencyKey}].");
        }

        throw new PspUnavailable("PSP answered {$response->status} without a definitive outcome.");
    }
}
