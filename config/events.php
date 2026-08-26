<?php

declare(strict_types=1);

use App\Domain\Events\Consumers\ArchiveConsumer;
use App\Domain\Events\Consumers\ProjectionConsumer;
use App\Domain\Events\Consumers\ReactionConsumer;

return [

    /*
    |--------------------------------------------------------------------------
    | Ingest authentication
    |--------------------------------------------------------------------------
    |
    | One static bearer token shared by every producer. Deliberately minimal —
    | the ingest hot path does auth and envelope validation only. Per-product
    | credentials and rotation arrive with the API/auth work (tech-debt #7).
    | Null means fail closed: no token configured, no ingestion.
    |
    */

    'ingest_token' => env('EVENTS_INGEST_TOKEN'),

    /*
    |--------------------------------------------------------------------------
    | Stream buffer
    |--------------------------------------------------------------------------
    |
    | The ingest buffer is a Redis Stream on the dedicated stream instance
    | (ADR-002). maxlen is an approximate XADD MAXLEN ~ bound — the app-side
    | trimming the instance's noeviction policy relies on.
    |
    | INVARIANT (ADR-003): backpressure.reject_all_above < stream.maxlen.
    | Ingestion must start refusing work before trimming could ever reach an
    | unconsumed entry; the provider refuses to boot if this is violated.
    |
    */

    'stream' => [
        'connection' => 'stream',
        'key' => 'events:ingest',
        'maxlen' => (int) env('EVENTS_STREAM_MAXLEN', 1_000_000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Deduplication
    |--------------------------------------------------------------------------
    |
    | Client-generated event_id, SET NX EX on the stream instance — the same
    | noeviction instance as the buffer, because an evicted dedup key silently
    | re-admits a duplicate. The TTL bounds memory: at 5k events/sec a 900s
    | window holds ~4.5M keys (~450MB), sized into the instance's maxmemory.
    | Duplicates arriving after the window are absorbed by the consumers, which
    | are idempotent regardless (archive INSERT IGNORE, PFADD/SETBIT, reaction
    | markers) — the window is an optimisation, not the correctness boundary.
    |
    */

    'dedup' => [
        'prefix' => 'events:dedup:',
        'ttl' => (int) env('EVENTS_DEDUP_TTL', 900),
    ],

    /*
    |--------------------------------------------------------------------------
    | Backpressure
    |--------------------------------------------------------------------------
    |
    | Shedding is by priority class, read from stream depth (XLEN, one O(1)
    | call per batch). Above shed_analytics_above, analytics events are shed
    | per-event (status "shed", still 202) while operational events pass.
    | Above reject_all_above the whole request gets 429 + Retry-After.
    |
    */

    'backpressure' => [
        'shed_analytics_above' => (int) env('EVENTS_SHED_ABOVE', 500_000),
        'reject_all_above' => (int) env('EVENTS_REJECT_ABOVE', 900_000),
        'retry_after_seconds' => (int) env('EVENTS_RETRY_AFTER', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Schema versioning
    |--------------------------------------------------------------------------
    |
    | Two live envelope payload versions at any time, so producers upgrade on
    | their own release cadence. Ingest rejects versions outside this set;
    | consumers upcast old versions through SchemaRegistry before applying.
    | The archive stores events exactly as received, version and all.
    |
    */

    'schema' => [
        'live_versions' => [1, 2],
    ],

    /*
    |--------------------------------------------------------------------------
    | Consumer groups
    |--------------------------------------------------------------------------
    |
    | claim_idle_ms: how long an entry may sit unacked in another consumer's
    | PEL before XAUTOCLAIM steals it. Must exceed the slowest honest batch.
    | max_deliveries: past this, an entry is a poison message and goes to the
    | dead-letter list instead of blocking the group forever.
    |
    | groups: the one source of truth for which consumer groups exist, name
    | => Consumer class. `events:work {group}` resolves and validates against
    | this map; `events:check-lag` expects every key here to exist on the
    | stream. XADD MAXLEN trims by aggregate stream length only — it has no
    | idea whether any one group has actually read an entry. A group whose
    | worker never starts (or died and stayed dead) never advances its
    | cursor, so entries it never got to can be trimmed out from under it
    | with zero signal anywhere else: no PEL row, no XAUTOCLAIM deletedIds,
    | nothing. This map plus max_lag/max_pending is what makes that failure
    | loud instead of silent.
    |
    */

    'consumers' => [
        'claim_idle_ms' => (int) env('EVENTS_CLAIM_IDLE_MS', 30_000),
        'max_deliveries' => (int) env('EVENTS_MAX_DELIVERIES', 6),
        'dead_letter_key' => 'events:dead',
        'groups' => [
            'archive' => ArchiveConsumer::class,
            'projections' => ProjectionConsumer::class,
            'reactions' => ReactionConsumer::class,
        ],
        'max_lag' => (int) env('EVENTS_MAX_GROUP_LAG', 100_000),
        'max_pending' => (int) env('EVENTS_MAX_GROUP_PENDING', 10_000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Projections
    |--------------------------------------------------------------------------
    |
    | HLL (DAU per product per day) and bitmap (active-user bitmap per day)
    | live on the cache instance: they are fully reconstructible by replaying
    | the archive, so eviction is an inconvenience, never a correctness event.
    |
    */

    'projections' => [
        'connection' => 'cache',
        'retention_days' => (int) env('EVENTS_PROJECTION_RETENTION_DAYS', 90),
    ],

    /*
    |--------------------------------------------------------------------------
    | Domain reactions
    |--------------------------------------------------------------------------
    |
    | type => list of queued job classes, dispatched by the reactions consumer.
    | At-least-once: the reacted-marker is written after dispatch, so a crash
    | between the two re-dispatches on redelivery — reaction jobs must be
    | idempotent. Billing wires its reactions here in Phase 3 (tech-debt #9).
    |
    */

    'reactions' => [
        'marker_prefix' => 'events:reacted:',
        'marker_ttl' => (int) env('EVENTS_REACTION_MARKER_TTL', 86_400),
        'map' => [
            // 'payment.failed' => [\App\Domain\Billing\Jobs\HandlePaymentFailure::class],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Archive retention
    |--------------------------------------------------------------------------
    |
    | Months of events_archive to keep. On MySQL, `events:partitions` drops
    | whole monthly partitions (a metadata operation); MassPrunable on the
    | model is the portable fallback and the belt to the partition braces.
    |
    */

    'archive' => [
        'retention_months' => (int) env('EVENTS_RETENTION_MONTHS', 13),
    ],

];
