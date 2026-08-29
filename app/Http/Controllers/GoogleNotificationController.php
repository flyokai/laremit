<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Billing\Enums\Store;
use App\Domain\Billing\Stores\Google\GoogleNotificationParser;
use App\Domain\Billing\Webhooks\WebhookInbox;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use JsonException;

/**
 * POST /v1/iap/google/notifications?token=… — Real-Time Developer
 * Notifications, pushed by Cloud Pub/Sub. Authenticity is the push
 * subscription's verification token (the OIDC-token variant is tech-debt
 * #13); the dedup key is the Pub/Sub messageId, because Pub/Sub delivery
 * is at-least-once by contract. Nothing in the body is believed — the
 * handler re-fetches the purchase from the Play API — so even a forged
 * message can only cause a harmless re-read.
 */
final class GoogleNotificationController
{
    public function __invoke(Request $request, WebhookInbox $inbox): JsonResponse
    {
        $expected = config('billing.stores.google.pubsub_token');

        if (! is_string($expected) || $expected === '') {
            return response()->json(['error' => 'pubsub_token_unconfigured'], 503);
        }

        $given = $request->query('token');

        if (! is_string($given) || ! hash_equals($expected, $given)) {
            return response()->json(['error' => 'invalid_token'], 401);
        }

        $raw = $request->getContent();

        try {
            $envelope = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
            $notification = GoogleNotificationParser::parse(is_array($envelope) ? $envelope : []);
        } catch (JsonException|InvalidArgumentException) {
            return response()->json(['error' => 'malformed_payload'], 400);
        }

        $receipt = $inbox->receive(
            Store::Google,
            $notification->messageId,
            $notification->typeName(),
            $raw,
            $notification->publishTime,
        );

        return response()->json(['received' => true, 'duplicate' => $receipt->duplicate]);
    }
}
