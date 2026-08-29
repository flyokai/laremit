<?php

declare(strict_types=1);

namespace App\MockPsp\Http;

use App\MockPsp\MockPsp;
use App\MockPsp\MockPspSettings;
use App\MockPsp\MockPspTimedOut;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * HTTP face of the mock PSP: the charge endpoint the HttpPspClient calls,
 * the read side reconciliation queries, refunds, and a config endpoint
 * chaos runs use to flip failure rates at runtime. Local tooling only
 * (config mockpsp.enabled) — never deployed.
 */
final class MockPspController
{
    public function charge(Request $request, MockPsp $psp, MockPspSettings $settings): JsonResponse
    {
        $key = $request->header('Idempotency-Key');

        if (! is_string($key) || $key === '' || strlen($key) > 128) {
            return response()->json(['error' => 'missing_idempotency_key'], 400);
        }

        /** @var array{amount_minor: int, currency: string, metadata?: array<string, mixed>} $validated */
        $validated = $request->validate([
            'amount_minor' => ['required', 'integer', 'min:1'],
            'currency' => ['required', 'string', 'size:3'],
            'metadata' => ['sometimes', 'array'],
        ]);

        try {
            $response = $psp->charge($key, $validated['amount_minor'], strtoupper($validated['currency']), $validated['metadata'] ?? []);
        } catch (MockPspTimedOut $e) {
            // Make the timeout real: outlast the caller's client timeout,
            // then answer the truth into a connection nobody is holding.
            sleep($settings->timeoutSleepSeconds());

            return response()->json($e->truth->body, $e->truth->status);
        }

        return response()->json($response->body, $response->status);
    }

    public function refund(Request $request, MockPsp $psp, string $charge): JsonResponse
    {
        /** @var array{amount_minor?: int, reason?: string} $validated */
        $validated = $request->validate([
            'amount_minor' => ['sometimes', 'integer', 'min:1'],
            'reason' => ['sometimes', 'string', 'max:32'],
        ]);

        $response = $psp->refund($charge, $validated['amount_minor'] ?? null, $validated['reason'] ?? null);

        return response()->json($response->body, $response->status);
    }

    /**
     * GET /v1/charges?since=<iso>  — the listing reconciliation pulls.
     * GET /v1/charges?idempotency_key=<key> — lookup by the caller's key.
     */
    public function index(Request $request, MockPsp $psp): JsonResponse
    {
        $key = $request->query('idempotency_key');

        if (is_string($key) && $key !== '') {
            $charge = $psp->findCharge($key);

            return $charge === null
                ? response()->json(['error' => 'no_such_charge'], 404)
                : response()->json(['charge' => $charge]);
        }

        $since = $request->query('since');

        if (! is_string($since) || $since === '') {
            return response()->json(['error' => 'since_or_idempotency_key_required'], 400);
        }

        return response()->json(['charges' => $psp->listCharges(CarbonImmutable::parse($since)->utc())]);
    }

    public function currentSettings(MockPspSettings $settings): JsonResponse
    {
        return response()->json($settings->all());
    }

    public function configure(Request $request, MockPspSettings $settings): JsonResponse
    {
        /** @var array<string, mixed> $overrides */
        $overrides = $request->validate([
            'outcomes' => ['sometimes', 'array'],
            'outcomes.declined_rate' => ['sometimes', 'numeric', 'between:0,1'],
            'outcomes.timeout_rate' => ['sometimes', 'numeric', 'between:0,1'],
            'webhook' => ['sometimes', 'array'],
            'webhook.delay_seconds' => ['sometimes', 'array', 'size:2'],
            'webhook.delay_seconds.*' => ['integer', 'min:0', 'max:60'],
            'webhook.duplicate_rate' => ['sometimes', 'numeric', 'between:0,1'],
            'webhook.drop_rate' => ['sometimes', 'numeric', 'between:0,1'],
            'timeout' => ['sometimes', 'array'],
            'timeout.sleep_seconds' => ['sometimes', 'integer', 'min:0', 'max:30'],
        ]);

        $settings->override($overrides);

        return response()->json($settings->all());
    }

    public function reset(MockPspSettings $settings): JsonResponse
    {
        $settings->reset();

        return response()->json($settings->all());
    }
}
