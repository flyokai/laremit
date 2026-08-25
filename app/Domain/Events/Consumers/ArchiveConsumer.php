<?php

declare(strict_types=1);

namespace App\Domain\Events\Consumers;

use App\Domain\Events\Contracts\Consumer;
use App\Domain\Events\Models\ArchivedEvent;
use App\Domain\Events\Support\Envelope;
use Carbon\CarbonImmutable;

/**
 * Writes every event to the partitioned archive table — the system of record
 * the projections can always be rebuilt from.
 *
 * Events are stored exactly as received (original schema_version, original
 * payload); upcasting happens on read/replay, so the archive is history, not
 * an interpretation of it.
 *
 * Idempotency: INSERT IGNORE against the (event_id, received_at) unique key.
 * A redelivered batch re-inserts and the database drops the rows it already
 * has — no read-check race, no double-count.
 */
final readonly class ArchiveConsumer implements Consumer
{
    private const CHUNK = 200;

    public function apply(array $envelopes): void
    {
        if ($envelopes === []) {
            return;
        }

        $createdAt = CarbonImmutable::now()->format('Y-m-d H:i:s');

        $rows = array_map(static fn (Envelope $e): array => [
            'event_id' => $e->eventId,
            'user_id' => $e->userId,
            'product' => $e->product,
            'type' => $e->type,
            'schema_version' => $e->schemaVersion,
            'priority' => $e->priority->value,
            'payload' => json_encode($e->payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'occurred_at' => $e->occurredAt->format('Y-m-d H:i:s.v'),
            'received_at' => $e->receivedAt->format('Y-m-d H:i:s'),
            'created_at' => $createdAt,
        ], $envelopes);

        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            ArchivedEvent::query()->insertOrIgnore($chunk);
        }
    }
}
