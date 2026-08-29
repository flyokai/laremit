<?php

declare(strict_types=1);

namespace App\Domain\Billing\Psp;

use App\Domain\Billing\Contracts\PspClient;
use App\Domain\Billing\Exceptions\PspTimedOut;
use App\Domain\Billing\Exceptions\PspUnavailable;
use App\Domain\Billing\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
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
            $response = $this->request()
                ->withHeaders(['Idempotency-Key' => $idempotencyKey])
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

    public function listCharges(CarbonImmutable $since): array
    {
        $response = $this->get('/v1/charges', ['since' => $since->toISOString()]);

        if (! $response->successful()) {
            throw new PspUnavailable("PSP charge listing answered {$response->status()}.");
        }

        $charges = [];

        foreach (is_array($response->json('charges')) ? $response->json('charges') : [] as $charge) {
            if (is_array($charge)) {
                $charges[] = RemoteCharge::fromArray($charge);
            }
        }

        return $charges;
    }

    public function findCharge(string $idempotencyKey): ?RemoteCharge
    {
        $response = $this->get('/v1/charges', ['idempotency_key' => $idempotencyKey]);

        if ($response->status() === 404) {
            return null;
        }

        if (! $response->successful()) {
            throw new PspUnavailable("PSP charge lookup answered {$response->status()}.");
        }

        $charge = $response->json('charge');

        return is_array($charge) ? RemoteCharge::fromArray($charge) : null;
    }

    /**
     * @param  array<string, string>  $query
     */
    private function get(string $path, array $query): Response
    {
        try {
            return $this->request()->get($path, $query);
        } catch (ConnectionException $e) {
            throw new PspUnavailable("PSP gave no answer: {$e->getMessage()}", previous: $e);
        }
    }

    private function request(): PendingRequest
    {
        return $this->http
            ->baseUrl($this->baseUrl)
            ->timeout($this->timeoutSeconds)
            ->connectTimeout(min(2.0, $this->timeoutSeconds))
            ->acceptJson();
    }
}
