<?php

declare(strict_types=1);

namespace App\Domain\Outbox;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * One domain fact, ready for the outbox. Immutable and validated at
 * construction: a malformed event must fail in the publisher's transaction —
 * where the bug is — never later at the relay, where all that's left is a
 * dead letter and a mystery.
 *
 * The payload is thin-plus (ADR-006): the aggregate's identity plus the few
 * fields every consumer needs, versioned so it can grow. userId and product
 * are first-class because the envelope this event ships in (the Phase 2
 * ingest envelope) carries them as identity fields; payload() folds them in
 * so the payload is self-contained even read in isolation.
 */
final readonly class DomainEvent
{
    private const TYPE_PATTERN = '/^[a-z0-9]+(?:[._-][a-z0-9]+)*$/';

    private const PRODUCT_PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $type,
        public string $aggregateType,
        public string $aggregateId,
        public string $idempotencyKey,
        public ?int $userId,
        public string $product,
        public CarbonImmutable $occurredAt,
        public array $payload,
        public int $schemaVersion = 1,
    ) {
        if ($type === '' || strlen($type) > 128 || preg_match(self::TYPE_PATTERN, $type) !== 1) {
            throw new InvalidArgumentException("Domain event type [{$type}] is not a dot-separated lowercase identifier.");
        }

        if ($product === '' || strlen($product) > 64 || preg_match(self::PRODUCT_PATTERN, $product) !== 1) {
            throw new InvalidArgumentException("Domain event product [{$product}] is not a lowercase slug.");
        }

        if ($userId !== null && $userId < 1) {
            throw new InvalidArgumentException('Domain event user_id must be a positive integer or null.');
        }

        if ($idempotencyKey === '' || strlen($idempotencyKey) > 160) {
            throw new InvalidArgumentException('Domain event idempotency key must be 1–160 characters.');
        }
    }

    /**
     * The stored payload: the caller's fields with the identity folded in.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return ['user_id' => $this->userId, 'product' => $this->product] + $this->payload;
    }
}
