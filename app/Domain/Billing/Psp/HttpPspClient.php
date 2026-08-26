<?php

declare(strict_types=1);

namespace App\Domain\Billing\Psp;

use App\Domain\Billing\Contracts\PspClient;
use App\Domain\Billing\Exceptions\PspTimedOut;
use App\Domain\Billing\Exceptions\PspUnavailable;
use App\Domain\Billing\Money\Money;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use RuntimeException;

/**
 * Real-HTTP PSP client. No HTTP-layer retries on purpose: retrying is the
 * ChargeJob's decision (with backoff, against the same idempotency key),
 * and a hidden transport retry would double-count attempt budgets.
 */
final readonly class HttpPspClient implements PspClient
{
    public function __construct(
        private Http $http,
        private string $baseUrl,
        private float $timeoutSeconds,
    ) {}

    public function charge(string $idempotencyKey, Money $amount, array $metadata): ChargeResult
    {
        try {
            $response = $this->http
                ->baseUrl($this->baseUrl)
                ->timeout($this->timeoutSeconds)
                ->connectTimeout(min(2.0, $this->timeoutSeconds))
                ->withHeaders(['Idempotency-Key' => $idempotencyKey])
                ->acceptJson()
                ->post('/v1/charges', [
                    'amount_minor' => $amount->minor,
                    'currency' => $amount->currency,
                    'metadata' => $metadata,
                ]);
        } catch (ConnectionException $e) {
            throw new PspTimedOut("PSP gave no answer: {$e->getMessage()}", previous: $e);
        }

        $chargeId = $response->json('charge_id');

        if ($response->status() === 201 && is_string($chargeId)) {
            return ChargeResult::succeeded($chargeId);
        }

        if ($response->status() === 402 && is_string($chargeId)) {
            $declineCode = $response->json('decline_code');

            return ChargeResult::declined($chargeId, is_string($declineCode) ? $declineCode : 'card_declined');
        }

        if ($response->status() === 409) {
            // Same key, different request: our keys are ULIDs unique per
            // intent, so this is a programming error, not a payment outcome.
            throw new RuntimeException("PSP reports idempotency key reuse for [{$idempotencyKey}].");
        }

        // 5xx / unexpected shape: no definitive answer.
        throw new PspUnavailable("PSP answered {$response->status()} without a definitive outcome.");
    }
}
