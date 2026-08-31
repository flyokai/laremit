<?php

declare(strict_types=1);

namespace App\Domain\Outbox\Models;

use App\Domain\Events\Enums\Priority;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

/**
 * One committed domain fact awaiting (or done with) delivery. The row IS the
 * publish guarantee: it was written in the same transaction as the state
 * change it describes, so it exists if and only if the change committed.
 *
 * Three states, two nullable columns:
 *  - pending        dispatched_at NULL, dead_lettered_at NULL — the relay's queue
 *  - dispatched     dispatched_at set — on the stream, at least once
 *  - dead-lettered  dead_lettered_at set — failed envelope validation; parked
 *    out of the relay's claim until `outbox:replay` re-arms it
 *
 * @property int $id
 * @property string $aggregate_type
 * @property string $aggregate_id
 * @property string $type
 * @property int $schema_version
 * @property string $payload
 * @property string $idempotency_key
 * @property CarbonImmutable $occurred_at
 * @property CarbonImmutable $available_at
 * @property CarbonImmutable|null $dispatched_at
 * @property CarbonImmutable|null $dead_lettered_at
 * @property string|null $last_error
 * @property CarbonImmutable $created_at
 */
#[Fillable([
    'aggregate_type',
    'aggregate_id',
    'type',
    'schema_version',
    'payload',
    'idempotency_key',
    'occurred_at',
    'available_at',
    'dispatched_at',
    'dead_lettered_at',
    'last_error',
    'created_at',
])]
final class OutboxMessage extends Model
{
    use MassPrunable;

    public $timestamps = false;

    /**
     * The stream entry id, derived (UUIDv5-style, SHA-1 name-based) from the
     * idempotency key. Deterministic on purpose: a relay that crashed between
     * publish and mark-dispatched re-publishes the SAME event id, so the
     * duplicate is caught by the ingest dedup window, and past that window by
     * every consumer's own idempotency. Randomness here would silently turn
     * one fact into two events.
     */
    public function eventId(): string
    {
        $hash = sha1('laremit:outbox:'.$this->idempotency_key);

        return sprintf(
            '%s-%s-5%s-%x%s-%s',
            substr($hash, 0, 8),
            substr($hash, 8, 4),
            substr($hash, 13, 3),
            (hexdec($hash[16]) & 0x3) | 0x8,
            substr($hash, 17, 3),
            substr($hash, 20, 12),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function decodedPayload(): array
    {
        $decoded = json_decode($this->payload, true, 32, JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * The producer-shaped raw event for the Ingestor — the relay submits
     * exactly what a client SDK would, and gets exactly the same validation.
     * user_id and product live in the payload (folded in by DomainEvent) and
     * are lifted to envelope identity here. Priority is always operational:
     * domain facts are never load-shed.
     *
     * @return array<string, mixed>
     */
    public function envelopeInput(): array
    {
        $payload = $this->decodedPayload();

        return [
            'event_id' => $this->eventId(),
            'type' => $this->type,
            'schema_version' => $this->schema_version,
            'occurred_at' => $this->occurred_at->toISOString(),
            'user_id' => $payload['user_id'] ?? null,
            'product' => $payload['product'] ?? null,
            'priority' => Priority::Operational->value,
            'payload' => $payload,
        ];
    }

    /**
     * Dispatched rows age out — the archive is the history, the outbox is a
     * carrier. Pending rows are the relay's backlog and dead-lettered rows
     * are unfinished work; neither is ever pruned.
     *
     * @return Builder<OutboxMessage>
     */
    public function prunable(): Builder
    {
        return self::query()
            ->whereNotNull('dispatched_at')
            ->where('dispatched_at', '<', CarbonImmutable::now()->subDays((int) config('outbox.retention_days')));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'schema_version' => 'integer',
            'occurred_at' => 'immutable_datetime',
            'available_at' => 'immutable_datetime',
            'dispatched_at' => 'immutable_datetime',
            'dead_lettered_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
