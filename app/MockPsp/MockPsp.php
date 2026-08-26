<?php

declare(strict_types=1);

namespace App\MockPsp;

use App\MockPsp\Jobs\DeliverPspWebhook;
use App\MockPsp\Models\PspCharge;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

/**
 * The mock PSP's core. Built to the roadmap's warning — "build this well;
 * everything else depends on it" — which concretely means three behaviours
 * real PSPs have and toy mocks skip:
 *
 *  1. Idempotency keys are honoured exactly: a repeated key with the same
 *     request replays the stored response byte-for-byte, never re-decides;
 *     the same key with a different request is a 409.
 *  2. Timeouts can lie: the default simulated timeout records a SUCCESSFUL
 *     charge and fires its webhooks anyway. The caller saw nothing; the
 *     money moved. This is the case that separates idempotent payment
 *     systems from double-charging ones.
 *  3. Webhooks are hostile: delivered late (random delay), duplicated, and
 *     therefore out of order relative to each other and to API responses.
 *
 * Deterministic outcomes by amount convention (see config/mockpsp.php):
 * amount_minor % 100 of 1 => timeout-but-charged, 2 => declined,
 * 3 => timeout-nothing-recorded; metadata.force overrides everything.
 */
final readonly class MockPsp
{
    public function __construct(private MockPspSettings $settings) {}

    /**
     * @param  array<string, mixed>  $metadata
     *
     * @throws MockPspTimedOut
     */
    public function charge(string $idempotencyKey, int $amountMinor, string $currency, array $metadata): PspResponse
    {
        $requestHash = $this->hash($amountMinor, $currency, $metadata);

        $existing = PspCharge::query()->where('idempotency_key', $idempotencyKey)->first();

        if ($existing !== null) {
            return $this->replay($existing, $requestHash);
        }

        return match ($this->decide($amountMinor, $metadata)) {
            'declined' => $this->settle($idempotencyKey, $requestHash, $amountMinor, $currency, $metadata, succeeded: false),
            'timeout_charged' => throw new MockPspTimedOut(
                $this->settle($idempotencyKey, $requestHash, $amountMinor, $currency, $metadata, succeeded: true),
            ),
            'timeout_lost' => throw new MockPspTimedOut(
                new PspResponse(504, ['error' => 'gateway_timeout']),
            ),
            default => $this->settle($idempotencyKey, $requestHash, $amountMinor, $currency, $metadata, succeeded: true),
        };
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function decide(int $amountMinor, array $metadata): string
    {
        $force = $metadata['force'] ?? null;

        if (is_string($force) && in_array($force, ['succeed', 'declined', 'timeout_charged', 'timeout_lost'], true)) {
            return $force;
        }

        $outcome = match ($amountMinor % 100) {
            1 => 'timeout_charged',
            2 => 'declined',
            3 => 'timeout_lost',
            default => null,
        };

        if ($outcome !== null) {
            return $outcome;
        }

        $roll = mt_rand() / mt_getrandmax();

        if ($roll < $this->settings->declinedRate()) {
            return 'declined';
        }

        // Random timeouts always charge: the ambiguous kind is the kind
        // worth simulating by default.
        if ($roll < $this->settings->declinedRate() + $this->settings->timeoutRate()) {
            return 'timeout_charged';
        }

        return 'succeed';
    }

    /**
     * Record the charge, queue its hostile webhooks, return the response.
     *
     * @param  array<string, mixed>  $metadata
     */
    private function settle(
        string $idempotencyKey,
        string $requestHash,
        int $amountMinor,
        string $currency,
        array $metadata,
        bool $succeeded,
    ): PspResponse {
        $chargeId = 'ch_'.Str::ulid();
        $declineCode = $succeeded ? null : 'card_declined';

        $body = $succeeded
            ? ['charge_id' => $chargeId, 'status' => 'succeeded', 'amount_minor' => $amountMinor, 'currency' => $currency]
            : ['charge_id' => $chargeId, 'status' => 'failed', 'decline_code' => $declineCode];

        $status = $succeeded ? 201 : 402;

        try {
            $charge = PspCharge::query()->create([
                'charge_id' => $chargeId,
                'idempotency_key' => $idempotencyKey,
                'request_hash' => $requestHash,
                'amount_minor' => $amountMinor,
                'currency' => $currency,
                'status' => $succeeded ? PspCharge::STATUS_SUCCEEDED : PspCharge::STATUS_FAILED,
                'decline_code' => $declineCode,
                'metadata' => $metadata,
                'response_status' => $status,
                'response_body' => $body,
            ]);
        } catch (QueryException) {
            // Two concurrent requests raced on the key; the constraint chose
            // a winner. Replay the winner's stored response.
            $winner = PspCharge::query()->where('idempotency_key', $idempotencyKey)->firstOrFail();

            return $this->replay($winner, $requestHash);
        }

        $this->scheduleWebhooks($charge);

        return new PspResponse($status, $body);
    }

    private function replay(PspCharge $existing, string $requestHash): PspResponse
    {
        if ($existing->request_hash !== $requestHash) {
            return new PspResponse(409, [
                'error' => 'idempotency_key_reuse',
                'detail' => 'This Idempotency-Key was already used with a different request.',
            ]);
        }

        return new PspResponse($existing->response_status, $existing->response_body);
    }

    private function scheduleWebhooks(PspCharge $charge): void
    {
        $payload = [
            'event_id' => 'evt_'.Str::ulid(),
            'type' => $charge->status === PspCharge::STATUS_SUCCEEDED ? 'charge.succeeded' : 'charge.failed',
            'created_at' => CarbonImmutable::now()->toISOString(),
            'data' => [
                'charge_id' => $charge->charge_id,
                'amount_minor' => $charge->amount_minor,
                'currency' => $charge->currency,
                'decline_code' => $charge->decline_code,
                'metadata' => $charge->metadata,
            ],
        ];

        [$minDelay, $maxDelay] = $this->settings->webhookDelayRange();

        DeliverPspWebhook::dispatch($payload)->delay(random_int($minDelay, max($minDelay, $maxDelay)));

        // The duplicate is a genuinely separate delivery with its own delay,
        // so it can land before OR after its twin — both orders must be safe.
        if (mt_rand() / mt_getrandmax() < $this->settings->webhookDuplicateRate()) {
            DeliverPspWebhook::dispatch($payload)->delay(random_int($minDelay, max($minDelay, $maxDelay) + 2));
        }
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function hash(int $amountMinor, string $currency, array $metadata): string
    {
        ksort($metadata);

        return hash('sha256', json_encode([$amountMinor, $currency, $metadata], JSON_THROW_ON_ERROR));
    }
}
