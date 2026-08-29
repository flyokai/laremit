<?php

declare(strict_types=1);

namespace App\Domain\Billing\Stores\Apple;

use App\Domain\Billing\Stores\StoreSubscriptionSnapshot;
use Carbon\CarbonImmutable;

/** One verified ASSN v2 notification, reduced to what the app acts on. */
final readonly class AppleNotification
{
    public function __construct(
        public string $uuid,
        /** "SUBSCRIBED/INITIAL_BUY", "DID_RENEW", ... — type plus subtype when present. */
        public string $type,
        public CarbonImmutable $signedDate,
        public StoreSubscriptionSnapshot $snapshot,
    ) {}
}
