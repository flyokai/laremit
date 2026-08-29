<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Billing\Enums\Store;
use App\Domain\Billing\Stores\Apple\AppleJwsVerifier;
use App\Domain\Billing\Webhooks\WebhookInbox;
use App\Support\Jws\JwsException;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use JsonException;

/**
 * POST /v1/iap/apple/notifications — App Store Server Notifications V2.
 * Authenticity is the ES256 signature on the JWS inside the body, verified
 * against the pinned key; the dedup key is Apple's notificationUUID. Same
 * edge discipline as the PSP: verify, persist raw, 200, queue.
 */
final class AppleNotificationController
{
    public function __invoke(Request $request, AppleJwsVerifier $verifier, WebhookInbox $inbox): JsonResponse
    {
        if (! $verifier->isConfigured()) {
            return response()->json(['error' => 'apple_signing_key_unconfigured'], 503);
        }

        $raw = $request->getContent();

        try {
            $body = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return response()->json(['error' => 'malformed_json'], 400);
        }

        $signedPayload = is_array($body) ? ($body['signedPayload'] ?? null) : null;

        if (! is_string($signedPayload) || $signedPayload === '') {
            return response()->json(['error' => 'malformed_payload'], 400);
        }

        try {
            $payload = $verifier->decode($signedPayload);
        } catch (JwsException) {
            return response()->json(['error' => 'invalid_signature'], 401);
        }

        $uuid = $payload['notificationUUID'] ?? null;
        $type = $payload['notificationType'] ?? null;
        $subtype = $payload['subtype'] ?? null;
        $signedDate = $payload['signedDate'] ?? null;

        if (! is_string($uuid) || $uuid === '' || ! is_string($type) || $type === '' || ! is_int($signedDate)) {
            return response()->json(['error' => 'malformed_payload'], 400);
        }

        $receipt = $inbox->receive(
            Store::Apple,
            $uuid,
            is_string($subtype) && $subtype !== '' ? "{$type}/{$subtype}" : $type,
            $raw,
            CarbonImmutable::createFromTimestampMs($signedDate, 'UTC'),
        );

        return response()->json(['received' => true, 'duplicate' => $receipt->duplicate]);
    }
}
