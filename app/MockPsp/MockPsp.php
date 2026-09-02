<?php

declare(strict_types=1);

namespace App\MockPsp;

use App\MockPsp\Jobs\DeliverPspWebhook;
use App\MockPsp\Models\PspCharge;
use App\MockPsp\Models\PspRefund;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
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
 *  3. Webhooks are hostile: delivered late (random delay), duplicated,
 *     dropped outright (drop_rate — Phase 4's lever), and therefore out
 *     of order relative to each other and to API responses.
 *
 * Deterministic outcomes by amount convention (see config/mockpsp.php):
 * amount_minor % 100 of 1 => timeout-but-charged, 2 => declined,
 * 3 => timeout-nothing-recorded; metadata.force overrides everything.
 *
 * Phase 4 adds the provider's read side — list and lookup — which is what
 * reconciliation asks when webhooks did not arrive, and refunds, which are
 * facts with their own ids.
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
     * Refund part or all of a succeeded charge. Each refund is its own
     * record with its own id and fires its own charge.refunded webhook.
     */
    public function refund(string $chargeId, ?int $amountMinor, ?string $reason): PspResponse
    {
        $charge = PspCharge::query()->where('charge_id', $chargeId)->first();

        if ($charge === null) {
            return new PspResponse(404, ['error' => 'no_such_charge']);
        }

        if ($charge->status !== PspCharge::STATUS_SUCCEEDED) {
            return new PspResponse(400, ['error' => 'charge_not_refundable']);
        }

        $alreadyRefunded = (int) PspRefund::query()->where('charge_id', $chargeId)->sum('amount_minor');
        $amountMinor ??= $charge->amount_minor - $alreadyRefunded;

        if ($amountMinor <= 0 || $alreadyRefunded + $amountMinor > $charge->amount_minor) {
            return new PspResponse(400, ['error' => 'amount_exceeds_charge']);
        }

        $refund = PspRefund::query()->create([
            'refund_id' => 're_'.Str::ulid(),
            'charge_id' => $chargeId,
            'amount_minor' => $amountMinor,
            'currency' => $charge->currency,
            'reason' => $reason,
        ]);

        $this->scheduleWebhook([
            'event_id' => 'evt_'.Str::ulid(),
            'type' => 'charge.refunded',
            'created_at' => CarbonImmutable::now()->toISOString(),
            'data' => [
                'refund_id' => $refund->refund_id,
                'charge_id' => $chargeId,
                'amount_minor' => $amountMinor,
                'currency' => $charge->currency,
                'reason' => $reason,
                'metadata' => $charge->metadata,
            ],
        ]);

        return new PspResponse(201, [
            'refund_id' => $refund->refund_id,
            'charge_id' => $chargeId,
            'amount_minor' => $amountMinor,
            'currency' => $charge->currency,
            'status' => 'succeeded',
        ]);
    }

    /**
     * The provider's ledger of charges since a point in time, refunds
     * included — what reconciliation pulls (theirs -> ours).
     *
     * @return list<array<string, mixed>>
     */
    public function listCharges(CarbonImmutable $since): array
    {
        $charges = PspCharge::query()
            ->where('created_at', '>=', $since)
            ->orderBy('id')
            ->get();

        $refunds = PspRefund::query()
            ->whereIn('charge_id', $charges->pluck('charge_id'))
            ->orderBy('id')
            ->get()
            ->groupBy('charge_id');

        return array_values($charges
            ->map(fn (PspCharge $charge): array => $this->describe($charge, array_values($refunds->get($charge->charge_id)?->all() ?? [])))
            ->all());
    }

    /**
     * Lookup by the caller's idempotency key (ours -> theirs). Null means
     * "this key never produced a charge here" — definitively.
     *
     * @return array<string, mixed>|null
     */
    public function findCharge(string $idempotencyKey): ?array
    {
        $charge = PspCharge::query()->where('idempotency_key', $idempotencyKey)->first();

        if ($charge === null) {
            return null;
        }

        return $this->describe($charge, array_values(PspRefund::query()->where('charge_id', $charge->charge_id)->orderBy('id')->get()->all()));
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

        $this->scheduleWebhook([
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
        ]);

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

    /**
     * Deliver late, maybe twice, maybe never. A dropped webhook is not
     * queued at all — from Laremit's side it is indistinguishable from a
     * provider outage, which is the point: only reconciliation can find
     * what it would have said.
     *
     * @param  array<string, mixed>  $payload
     */
    private function scheduleWebhook(array $payload): void
    {
        if (mt_rand() / mt_getrandmax() < $this->settings->webhookDropRate()) {
            Log::info('Mock PSP dropped a webhook on purpose.', [
                'event_id' => $payload['event_id'] ?? null,
                'type' => $payload['type'] ?? null,
            ]);

            return;
        }

        [$minDelay, $maxDelay] = $this->settings->webhookDelayRange();

        DeliverPspWebhook::dispatch($payload)->delay(random_int($minDelay, max($minDelay, $maxDelay)));

        // The duplicate is a genuinely separate delivery with its own delay,
        // so it can land before OR after its twin — both orders must be safe.
        if (mt_rand() / mt_getrandmax() < $this->settings->webhookDuplicateRate()) {
            DeliverPspWebhook::dispatch($payload)->delay(random_int($minDelay, max($minDelay, $maxDelay) + 2));
        }
    }

    /**
     * The wire shape of one charge with its refunds, as the list and
     * lookup endpoints return it.
     *
     * @param  list<PspRefund>  $refunds
     * @return array<string, mixed>
     */
    private function describe(PspCharge $charge, array $refunds): array
    {
        return [
            'charge_id' => $charge->charge_id,
            'idempotency_key' => $charge->idempotency_key,
            'amount_minor' => $charge->amount_minor,
            'currency' => $charge->currency,
            'status' => $charge->status,
            'decline_code' => $charge->decline_code,
            'metadata' => $charge->metadata,
            'created_at' => $charge->created_at?->toISOString(),
            'refunds' => array_map(static fn (PspRefund $refund): array => [
                'refund_id' => $refund->refund_id,
                'amount_minor' => $refund->amount_minor,
                'currency' => $refund->currency,
                'reason' => $refund->reason,
                'created_at' => $refund->created_at?->toISOString(),
            ], $refunds),
        ];
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
