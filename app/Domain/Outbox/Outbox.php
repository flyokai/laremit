<?php

declare(strict_types=1);

namespace App\Domain\Outbox;

use App\Domain\Outbox\Models\OutboxMessage;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * The write half of the transactional outbox (ADR-006). You cannot commit to
 * a database and a message broker atomically; this class makes the event a
 * row in the SAME transaction as the state change it describes, and leaves
 * delivery to the relay. The event is therefore published if and only if the
 * change committed — the dual-write problem dissolves into at-least-once
 * delivery, which idempotent consumers turn into exactly-once effects.
 *
 * publish() refuses to run outside a transaction: an outbox write on its own
 * is just the dual-write bug wearing a pattern's name.
 */
final readonly class Outbox
{
    /**
     * Record a domain fact for delivery. Returns false when this exact fact
     * (by idempotency key) is already recorded — a redelivered job or a
     * concurrent race re-deciding something already decided.
     */
    public function publish(DomainEvent $event): bool
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException(
                'Outbox messages must be published inside the transaction that carries the state change; '
                .'a bare publish is the dual-write bug the outbox exists to prevent.'
            );
        }

        $now = CarbonImmutable::now();

        try {
            OutboxMessage::query()->insert([
                'aggregate_type' => $event->aggregateType,
                'aggregate_id' => $event->aggregateId,
                'type' => $event->type,
                'schema_version' => $event->schemaVersion,
                'payload' => json_encode($event->payload(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'idempotency_key' => $event->idempotencyKey,
                // Millisecond precision, bound as a string for the same reason
                // as the subscription watermark: a Carbon binding would be
                // formatted to whole seconds.
                'occurred_at' => $event->occurredAt->utc()->format('Y-m-d H:i:s.v'),
                'available_at' => $now->format('Y-m-d H:i:s'),
                'created_at' => $now->format('Y-m-d H:i:s'),
            ]);
        } catch (QueryException $e) {
            if ($this->isUniqueViolation($e)) {
                return false;
            }

            throw $e;
        }

        return true;
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        // MySQL 1062; SQLite reports via message — same portability scope as
        // the ledger's twin of this check.
        return str_contains($e->getMessage(), 'Duplicate entry')
            || str_contains($e->getMessage(), 'UNIQUE constraint failed');
    }
}
