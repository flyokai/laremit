<?php

declare(strict_types=1);

namespace App\Domain\Billing\Webhooks;

/** What the edge learned while persisting one delivery. */
final readonly class InboxReceipt
{
    public function __construct(
        public int $webhookEventId,
        /** The (provider, event id) pair was already on file. */
        public bool $duplicate,
        /** A processing job was queued — for a new row, or a pending one found again. */
        public bool $dispatched,
    ) {}
}
