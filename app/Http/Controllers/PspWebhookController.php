<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Billing\Enums\Store;
use App\Domain\Billing\Webhooks\SignatureVerdict;
use App\Domain\Billing\Webhooks\WebhookInbox;
use App\Domain\Billing\Webhooks\WebhookSignature;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use JsonException;

/**
 * POST /v1/psp/webhook — the hardened edge, in the order that matters:
 *
 *   verify (HMAC over the raw bytes + signed timestamp within tolerance)
 *   → persist raw, UNIQUE(provider, provider_event_id)
 *   → 200 fast
 *   → queue, if the row is still pending
 *
 * It still does not apply anything: duplicates and reordering are the
 * funnels' problem, solved once under a row lock for every path. What the
 * edge adds is a durable, deduplicated record of every delivery and an
 * acknowledgement that means "stored", not "understood".
 *
 * Non-2xx semantics: 401 on any signature problem (resending the same
 * bytes cannot help; a spike of these means our secret is wrong), 400 on
 * a body we cannot even identify, 503 when we are misconfigured — never a
 * 200 for something we failed to store, because that loses it for good.
 */
final class PspWebhookController
{
    public function __invoke(Request $request, WebhookInbox $inbox): JsonResponse
    {
        $secret = config('billing.webhook_secret');

        if (! is_string($secret) || $secret === '') {
            return response()->json(['error' => 'webhook_secret_unconfigured'], 503);
        }

        $raw = $request->getContent();
        $header = $request->header(WebhookSignature::HEADER);

        $verdict = WebhookSignature::verify(
            $raw,
            is_string($header) ? $header : null,
            $secret,
            (int) config('billing.webhooks.tolerance_seconds'),
        );

        if ($verdict !== SignatureVerdict::Valid) {
            return response()->json([
                'error' => $verdict === SignatureVerdict::Stale ? 'stale_timestamp' : 'invalid_signature',
            ], 401);
        }

        try {
            $payload = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return response()->json(['error' => 'malformed_json'], 400);
        }

        $eventId = is_array($payload) ? ($payload['event_id'] ?? null) : null;
        $type = is_array($payload) ? ($payload['type'] ?? null) : null;
        $createdAt = is_array($payload) ? ($payload['created_at'] ?? null) : null;

        if (! is_string($eventId) || $eventId === '' || strlen($eventId) > 128 || ! is_string($type) || $type === '') {
            return response()->json(['error' => 'malformed_payload'], 400);
        }

        $receipt = $inbox->receive(
            Store::Psp,
            $eventId,
            $type,
            $raw,
            is_string($createdAt) ? CarbonImmutable::parse($createdAt)->utc() : null,
        );

        return response()->json(['received' => true, 'duplicate' => $receipt->duplicate]);
    }
}
