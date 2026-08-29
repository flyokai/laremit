<?php

declare(strict_types=1);

namespace App\Domain\Billing\Webhooks;

use App\Domain\Billing\Models\WebhookEvent;

/**
 * One per provider. The contract: read the persisted raw payload, apply
 * it idempotently, say what happened. Throw only for transient failures
 * the queue should retry (a store API that is down); everything the
 * payload itself is wrong about is a discarded outcome, not an exception.
 */
interface WebhookHandler
{
    public function handle(WebhookEvent $event): WebhookOutcome;
}
