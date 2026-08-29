<?php

declare(strict_types=1);

namespace App\Domain\Billing\Webhooks;

use App\Domain\Billing\Enums\WebhookEventStatus;

/**
 * A handler's verdict on one delivery: the row's final status plus a short
 * outcome word for the record — or a request to try again later, for the
 * one case (a refund outrunning its charge) where waiting is correct.
 */
final readonly class WebhookOutcome
{
    private function __construct(
        public WebhookEventStatus $status,
        public string $outcome,
        public ?int $retryAfterSeconds = null,
    ) {}

    /** Handled — including as a duplicate, a stale delivery, or a deliberately ignored type. */
    public static function processed(string $outcome): self
    {
        return new self(WebhookEventStatus::Processed, $outcome);
    }

    /** Unusable: nothing to apply, retrying would not change that. Kept for forensics. */
    public static function discarded(string $outcome): self
    {
        return new self(WebhookEventStatus::Discarded, $outcome);
    }

    /** Not yet applicable; release the job and look again. */
    public static function retryLater(string $outcome, int $seconds): self
    {
        return new self(WebhookEventStatus::Pending, $outcome, $seconds);
    }

    public function wantsRetry(): bool
    {
        return $this->retryAfterSeconds !== null;
    }
}
