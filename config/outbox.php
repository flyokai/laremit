<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Relay (ADR-006)
    |--------------------------------------------------------------------------
    |
    | batch: rows per SKIP LOCKED claim — also the unit of loss-free crash
    | recovery, since an interrupted batch rolls back whole. sleep_ms: idle
    | poll interval, and therefore the floor on delivery latency when the
    | outbox is quiet. Polling MySQL is a deliberate simplicity trade
    | (tech-debt #18); CDC replaces it if the latency floor starts to matter.
    |
    */

    'relay' => [
        'batch' => (int) env('OUTBOX_RELAY_BATCH', 200),
        'sleep_ms' => (int) env('OUTBOX_RELAY_SLEEP_MS', 500),
    ],

    /*
    |--------------------------------------------------------------------------
    | Backlog alarm
    |--------------------------------------------------------------------------
    |
    | A dead relay is silent — rows keep committing, nothing downstream knows
    | what it never received — so outbox:status --check alarms on the AGE of
    | the oldest pending message. The threshold must exceed an honest worst
    | case: a full backpressure retry cycle plus one relay restart.
    |
    */

    'alarm' => [
        'max_backlog_age_seconds' => (int) env('OUTBOX_MAX_BACKLOG_AGE', 300),
    ],

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    |
    | retention_days: dispatched outbox rows are a carrier, not a record —
    | the events archive is the record — so they age out fast. Pending and
    | dead-lettered rows are never pruned; they are unfinished work.
    |
    | consumptions_retention_days: consumption markers are each consumer's
    | dedup memory and must outlive every redelivery horizon (stream
    | retention, dead-letter replay). Pruning one reopens that event's dedup
    | window — tech-debt #17.
    |
    */

    'retention_days' => (int) env('OUTBOX_RETENTION_DAYS', 7),
    'consumptions_retention_days' => (int) env('OUTBOX_CONSUMPTIONS_RETENTION_DAYS', 30),

];
