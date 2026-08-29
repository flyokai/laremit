<?php

declare(strict_types=1);

namespace App\Domain\Billing\Stores\Google;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use JsonException;

/**
 * Pub/Sub push envelope -> GoogleNotification. The interesting part is
 * two layers of encoding: the envelope's message.data is base64 of the
 * RTDN JSON, which in turn wraps subscriptionNotification.
 */
final class GoogleNotificationParser
{
    /**
     * @param  array<string, mixed>  $envelope
     *
     * @throws InvalidArgumentException
     */
    public static function parse(array $envelope): GoogleNotification
    {
        $message = $envelope['message'] ?? null;

        if (! is_array($message)) {
            throw new InvalidArgumentException('Pub/Sub envelope has no message.');
        }

        $messageId = $message['messageId'] ?? $message['message_id'] ?? null;
        $publishTime = $message['publishTime'] ?? $message['publish_time'] ?? null;
        $encoded = $message['data'] ?? null;

        if (! is_string($messageId) || $messageId === '' || ! is_string($publishTime) || ! is_string($encoded)) {
            throw new InvalidArgumentException('Pub/Sub message is missing messageId, publishTime or data.');
        }

        $raw = base64_decode($encoded, true);

        if ($raw === false) {
            throw new InvalidArgumentException('Pub/Sub message data is not base64.');
        }

        try {
            $rtdn = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidArgumentException('RTDN payload is not JSON.', previous: $e);
        }

        if (! is_array($rtdn) || ! is_string($rtdn['packageName'] ?? null)) {
            throw new InvalidArgumentException('RTDN payload is missing packageName.');
        }

        $eventTimeMillis = $rtdn['eventTimeMillis'] ?? null;

        if (! is_string($eventTimeMillis) && ! is_int($eventTimeMillis)) {
            throw new InvalidArgumentException('RTDN payload is missing eventTimeMillis.');
        }

        $subscription = $rtdn['subscriptionNotification'] ?? null;
        $isTest = isset($rtdn['testNotification']);

        $type = is_array($subscription) && is_int($subscription['notificationType'] ?? null) ? $subscription['notificationType'] : null;
        $token = is_array($subscription) && is_string($subscription['purchaseToken'] ?? null) ? $subscription['purchaseToken'] : null;
        $subscriptionId = is_array($subscription) && is_string($subscription['subscriptionId'] ?? null) ? $subscription['subscriptionId'] : null;

        return new GoogleNotification(
            $messageId,
            CarbonImmutable::parse($publishTime)->utc(),
            $rtdn['packageName'],
            CarbonImmutable::createFromTimestampMs((int) $eventTimeMillis, 'UTC'),
            $type,
            $token,
            $subscriptionId,
            $isTest,
        );
    }
}
