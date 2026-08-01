# ADR-002: Redis topology and eviction policy per instance

- **Status:** Accepted
- **Date:** 2026-08-01
- **Phase:** 1

## Context

Three workloads want Redis, and they want incompatible things from it:

| Workload | Contents | Losing a key means |
|---|---|---|
| Cache | Query results, computed entitlements, rate-limit counters | A slower request |
| Queue | Jobs we have already promised to run: `ChargeJob`, webhook processing | A payment that never happens, or an entitlement never granted |
| Event stream | The Phase 2 ingest buffer, before archive and projections | A silently dropped analytics event |

`maxmemory-policy` is a **per-instance** setting in Redis. It cannot be set per
logical database, and logical databases share one memory limit and one eviction
sweep. So the common shortcut — one Redis with `SELECT 0/1/2` — forces a single
answer to a question these three workloads answer differently.

That single answer is always wrong somewhere:

- Pick `allkeys-lru` and the cache is fine, but under memory pressure Redis is
  free to delete a queued `ChargeJob`. Nothing errors. Nothing retries. The
  customer is charged and never entitled, or entitled and never charged, and it
  surfaces days later as a ledger that does not balance.
- Pick `noeviction` and the queue is safe, but the cache stops accepting writes
  once it fills, turning a cache miss into a hard failure.

Two more forces:

- **Blast radius.** A 20,000/sec event burst filling one shared instance takes
  payments down with it. The Phase 6 demo — flood the events queue with 1M jobs
  and show payment p99 unchanged — is not demonstrable on shared memory.
- **Durability.** Losing the whole cache on restart is acceptable and even
  useful. Losing the queue on restart is not.

## Decision

Run **three separate Redis instances**, each configured for its own workload.

| Instance | Persistence | maxmemory | Policy | Rationale |
|---|---|---|---|---|
| `redis-cache` | none (`save ""`, AOF off) | 256mb | `allkeys-lru` | Fully reconstructible from MySQL. Evicting is correct behaviour. |
| `redis-queue` | AOF `everysec` + RDB | 512mb | `noeviction` | Holds promised work. OOM on write is a signal we can retry; eviction is silent loss. |
| `redis-stream` | AOF `everysec` | 1gb | `noeviction` | Bounded by app-side `XADD MAXLEN` trimming and by shedding load at ingest, never by eviction. |

Laravel connection map (`config/database.php`):

| Connection | Instance | Used by |
|---|---|---|
| `default`, `cache` | `redis-cache` | Cache store, sessions, rate limiting |
| `queue` | `redis-queue` | `config/queue.php` connection `redis` |
| `horizon` | `redis-queue`, own key prefix | Horizon metadata |
| `stream` | `redis-stream` | Phase 2 ingest |

Horizon's bookkeeping shares the queue instance rather than the cache instance,
because a supervisor list or failed-job record that gets evicted is the same
class of problem as an evicted job. The separate prefix keeps a Horizon metadata
flush from ever touching a pending job.

`appendfsync everysec` on queue and stream, not `always`: `always` costs an fsync
per write and would cap ingest well below the 5k/sec target. The exposure is up
to one second of accepted-but-unpersisted work on a hard kill. That is
acceptable **because Redis is not the system of record** — the ledger is in
MySQL with `innodb_flush_log_at_trx_commit = 1`, and events are re-drivable from
the archive. If Redis ever becomes authoritative for anything, this line has to
be revisited.

## Enforcement

An ADR that nothing checks is a comment. `App\Support\Health\Checks\RedisCheck`
reads `CONFIG GET maxmemory-policy` from the queue and stream instances on every
readiness probe and fails the probe if either is not `noeviction`.

This catches the specific mistake this ADR exists to prevent: pointing two of
`REDIS_CACHE_HOST` / `REDIS_QUEUE_HOST` / `REDIS_STREAM_HOST` at the same
container. That misconfiguration is invisible in every functional test — the app
works perfectly — and only shows up under memory pressure in production, as
missing money.

## Consequences

- Three containers instead of one: more memory reserved overall, three things to
  monitor, three failure modes.
- Cross-instance atomicity is gone. No Lua script or `MULTI` can span the cache
  and the queue. This is a constraint the code must respect and is a reason the
  outbox lives in MySQL rather than in Redis.
- Connection count roughly triples per PHP worker. Sized into `max_connections`
  planning from Phase 8 onward.
- In exchange, each workload gets a memory ceiling it cannot be starved out of by
  the other two — which is the entire point.

## When to revisit

- Managed Redis with per-database policy control would remove the deployment
  cost, but not the memory-ceiling argument.
- If the event stream outgrows a single instance's memory or throughput, it moves
  to Kafka rather than to a Redis cluster (ADR-003 records that trigger).
