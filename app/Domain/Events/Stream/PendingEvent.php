<?php

declare(strict_types=1);

namespace App\Domain\Events\Stream;

use App\Domain\Events\Support\Envelope;

/**
 * One stream entry as delivered to a consumer: the entry id (needed for the
 * ack), the decoded envelope, and how many times delivery has been attempted.
 *
 * A null envelope means the entry did not decode; the consumer loop dead-letters
 * it with $raw preserved for forensics instead of crashing the group on it.
 */
final readonly class PendingEvent
{
    /**
     * @param  array<string, string>  $raw
     */
    public function __construct(
        public string $id,
        public ?Envelope $envelope,
        public int $deliveries = 1,
        public array $raw = [],
    ) {}
}
