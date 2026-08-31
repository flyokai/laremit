<?php

declare(strict_types=1);

return [
    App\Domain\Billing\BillingServiceProvider::class,
    App\Domain\Events\EventsServiceProvider::class,
    App\Domain\Outbox\OutboxServiceProvider::class,
    App\Providers\AppServiceProvider::class,
    App\Providers\HorizonServiceProvider::class,
];
