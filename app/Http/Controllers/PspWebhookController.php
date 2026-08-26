<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Billing\Jobs\ProcessPspWebhook;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use JsonException;

/**
 * POST /v1/psp/webhook — verify the HMAC over the raw bytes, answer 200
 * fast, queue the application. Deliberately does NOT try to deduplicate or
 * order events: duplicates and reordering are ApplyChargeOutcome's problem,
 * solved once, under a row lock, for every delivery path.
 *
 * Phase 4 hardens this edge (timestamp tolerance, raw persistence with a
 * provider-event-id unique key, stale-transition rejection) — tech-debt #10.
 */
final class PspWebhookController
{
    public function __invoke(Request $request): JsonResponse
    {
        $secret = config('billing.webhook_secret');

        if (! is_string($secret) || $secret === '') {
            return response()->json(['error' => 'webhook_secret_unconfigured'], 503);
        }

        $raw = $request->getContent();
        $signature = $request->header('X-Psp-Signature');

        if (! is_string($signature) || ! hash_equals(hash_hmac('sha256', $raw, $secret), $signature)) {
            return response()->json(['error' => 'invalid_signature'], 401);
        }

        try {
            $payload = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return response()->json(['error' => 'malformed_json'], 400);
        }

        if (! is_array($payload)) {
            return response()->json(['error' => 'malformed_payload'], 400);
        }

        ProcessPspWebhook::dispatch($payload);

        return response()->json(['received' => true]);
    }
}
