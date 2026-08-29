<?php

declare(strict_types=1);

namespace App\Domain\Billing\Stores\Google;

use Carbon\CarbonImmutable;

/**
 * One Real-Time Developer Notification, unwrapped from its Pub/Sub push
 * envelope. Deliberately thin: RTDN carries a purchase token and a type,
 * never state. It is a hint to re-fetch, not data.
 */
final readonly class GoogleNotification
{
    public const RECOVERED = 1;

    public const RENEWED = 2;

    public const CANCELED = 3;

    public const PURCHASED = 4;

    public const ON_HOLD = 5;

    public const IN_GRACE_PERIOD = 6;

    public const RESTARTED = 7;

    public const PAUSED = 10;

    public const REVOKED = 12;

    public const EXPIRED = 13;

    private const NAMES = [
        self::RECOVERED => 'SUBSCRIPTION_RECOVERED',
        self::RENEWED => 'SUBSCRIPTION_RENEWED',
        self::CANCELED => 'SUBSCRIPTION_CANCELED',
        self::PURCHASED => 'SUBSCRIPTION_PURCHASED',
        self::ON_HOLD => 'SUBSCRIPTION_ON_HOLD',
        self::IN_GRACE_PERIOD => 'SUBSCRIPTION_IN_GRACE_PERIOD',
        self::RESTARTED => 'SUBSCRIPTION_RESTARTED',
        self::PAUSED => 'SUBSCRIPTION_PAUSED',
        self::REVOKED => 'SUBSCRIPTION_REVOKED',
        self::EXPIRED => 'SUBSCRIPTION_EXPIRED',
    ];

    public function __construct(
        /** Pub/Sub messageId — the dedup key; Pub/Sub is at-least-once by contract. */
        public string $messageId,
        public CarbonImmutable $publishTime,
        public string $packageName,
        public CarbonImmutable $eventTime,
        public ?int $notificationType,
        public ?string $purchaseToken,
        public ?string $subscriptionId,
        public bool $isTest,
    ) {}

    public function typeName(): string
    {
        if ($this->isTest) {
            return 'TEST_NOTIFICATION';
        }

        return self::NAMES[$this->notificationType] ?? "SUBSCRIPTION_TYPE_{$this->notificationType}";
    }

    public function isSubscriptionNotification(): bool
    {
        return ! $this->isTest && $this->purchaseToken !== null;
    }
}
