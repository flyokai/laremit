<?php

declare(strict_types=1);

namespace App\Domain\Events\Contracts;

use App\Domain\Events\Stream\PendingEvent;
use App\Domain\Events\Support\Envelope;

/**
 * The buffer between ingest and the consumer groups.
 *
 * This interface is the ADR-003 migration seam: today it is a Redis Stream;
 * if the triggers in that ADR fire it becomes a Kafka topic without the
 * ingest path or the consumers changing shape. Semantics implementations
 * must honour: append is at-least-once, reads are per-group, an event stays
 * pending until acked, and unacked events are claimable by another consumer.
 */
interface EventBuffer
{
    public function depth(): int;

    /**
     * Depth plus already-seen flags for the given event ids, in one round trip.
     *
     * @param  list<string>  $eventIds
     * @return array{int, list<bool>} depth, then seen flags aligned with input order
     */
    public function depthAndSeen(array $eventIds): array;

    /**
     * Append envelopes and mark their ids seen. Append-then-mark, in that
     * order: a crash between the two re-admits a duplicate (absorbed by
     * idempotent consumers), never loses an accepted event.
     *
     * @param  list<Envelope>  $envelopes
     */
    public function append(array $envelopes): void;

    public function ensureGroup(string $group): void;

    /**
     * @return list<PendingEvent>
     */
    public function readNew(string $group, string $consumer, int $count, int $blockMs): array;

    /**
     * Steal entries another consumer read but never acked — crash recovery.
     *
     * @return list<PendingEvent>
     */
    public function claimAbandoned(string $group, string $consumer, int $minIdleMs, int $count): array;

    /**
     * @param  list<string>  $ids
     */
    public function ack(string $group, array $ids): void;

    public function deadLetter(PendingEvent $event, string $reason): void;

    /**
     * Re-enter dead-lettered entries into the stream, oldest first. Replay
     * re-delivers to EVERY group — including ones that already processed the
     * entry before it was poisoned for another — and that is fine by
     * contract: consumers are idempotent, so re-delivery costs nothing. An
     * entry whose stored envelope no longer decodes cannot be replayed and
     * is kept in the dead-letter store for forensics.
     *
     * @return array{replayed: int, kept: int}
     */
    public function replayDeadLetters(int $limit): array;

    /**
     * Operational snapshot for the status command.
     *
     * @return array<string, mixed>
     */
    public function info(): array;
}
