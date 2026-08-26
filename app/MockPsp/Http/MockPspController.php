<?php

declare(strict_types=1);

namespace App\MockPsp\Http;

use App\MockPsp\MockPsp;
use App\MockPsp\MockPspSettings;
use App\MockPsp\MockPspTimedOut;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * HTTP face of the mock PSP: the charge endpoint the HttpPspClient calls,
 * and a config endpoint chaos runs use to flip failure rates at runtime.
 * Local tooling only (config mockpsp.enabled) — never deployed.
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
