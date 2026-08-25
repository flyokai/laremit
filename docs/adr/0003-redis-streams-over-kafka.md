# ADR-003: Redis Streams as the event buffer, with a named Kafka trigger

- **Status:** Accepted
- **Date:** 2026-08-25
- **Phase:** 2

## Context

The ingest API must accept 5,000 events/sec sustained (bursts to 20,000),
answer in under 50ms at p99, and hand the events to three independent
consumers — archive, projections, domain reactions — with at-least-once
delivery and crash recovery. "Event pipeline" in a job description usually
means Kafka, so the choice needs defending in both directions.

Forces:

1. **The archive table is the log.** Every event lands in partitioned MySQL
   within seconds of ingestion and is replayable from there for its full
   retention. The buffer therefore only has to cover the gap between ingest
   and archive — minutes, not months. Kafka's strongest property, long
   retention with replay from the broker, is duplicated infrastructure here.
2. **Delivery semantics needed:** at-least-once, per-group fan-out,
   crash-stolen redelivery, dead-lettering. Redis Streams provides all four
   natively: consumer groups, XACK, per-entry pending lists, XAUTOCLAIM.
3. **Operational budget:** one person. A Kafka deployment is brokers,
   a controller quorum, partition rebalancing, client tuning — a second
   distributed system to operate before the first one works.
4. **Ordering:** none of the three consumers needs global or per-key
   ordering. Archive inserts are commutative under INSERT IGNORE,
   projections are commutative by construction (PFADD/SETBIT), reactions are
   independent per event. This is the requirement that usually forces
   Kafka's partitioned logs, and it is absent.

## Decision

Buffer events in a **Redis Stream** on the dedicated stream instance
(ADR-002), one consumer group per pipeline stage, `XACK` on completion,
`XAUTOCLAIM` for crash recovery, `XADD MAXLEN ~` for app-side trimming.

Bounding rule, enforced at boot by the service provider:

```
shed_analytics_above < reject_all_above < stream maxlen
```

Backpressure must reject new work **before** trimming could ever discard an
unconsumed entry. Within the buffer, loss can then only mean "ingestion said
no", never "an accepted event evaporated". A trimmed-while-pending entry
(XAUTOCLAIM reports these) is logged as an error because it means this
invariant failed in production.

Dedup keys (`SET NX EX` on client-generated event ids) live on the same
noeviction instance: an evicted dedup key silently re-admits duplicates,
which makes them queue-class data, not cache-class. The dedup window is an
optimisation, not the correctness boundary — consumers are idempotent
regardless, so duplicates beyond the window cost work, never correctness.

The code depends on an `EventBuffer` interface, not on Redis. That interface
is the migration seam.

## Consequences

- **Single-node ceiling.** One stream on one instance: throughput is bounded
  by one Redis process (~100k+ simple ops/sec — an order of magnitude above
  the target) and buffer depth by one machine's memory. Accepted, and
  monitored via `events:status`.
- **No broker-side replay.** Replay beyond the buffer is a query over
  `events_archive`, at MySQL speed, through the same upcasting consumers.
  Acceptable because replay is an operational tool here, not a product
  feature.
- **At-least-once, not exactly-once.** A consumer killed mid-batch causes
  redelivery of the whole batch. Every consumer is idempotent by
  construction, which turns this into effective exactly-once — and that
  discipline is load-bearing and tested (the kill-mid-batch chaos test).
- Buys: zero new infrastructure, one client library, blocking reads (no
  poll loops), and the Phase 6 queue-isolation demo stays honest because
  the buffer already lives on its own instance.

## Alternatives considered

**Kafka (or Redpanda).** The default answer at this problem shape, rejected
at this problem *size*: its decisive advantages (partitioned ordering,
broker retention/replay, multi-team consumer ecosystems, horizontal broker
scaling) map to requirements this system does not have yet — see the
triggers below, which name the moment that changes.

**MySQL as the buffer** (insert + SKIP LOCKED workers). One system fewer,
but it puts a 5k rows/sec transient write load on the same instance that
must never contend with billing, and turns the buffer into the thing the
buffer exists to protect. Rejected.

**Laravel queues as the buffer.** Jobs are commands, not a log: no fan-out
to multiple groups from one entry, no replay cursor, and it would entangle
the event firehose with the payment queues that Phase 6 isolates.

## When to revisit — the migration trigger

Move the buffer to Kafka when **any** of these is observed, not before:

1. Sustained ingest approaches ~15k events/sec, or the buffer regularly
   exceeds 60% of the stream instance's memory with healthy consumers —
   the single-node ceiling is in sight.
2. A consumer genuinely needs per-key ordering (the first billing-grade
   projection with non-commutative updates would).
3. Replay from the broker becomes a product requirement (rebuilding a
   downstream team's store without touching MySQL), or a second team owns a
   consumer and needs isolation we cannot give inside one Redis.

Migration path, in order: implement `KafkaEventBuffer` behind the existing
interface → dual-write both buffers → move consumer groups one at a time
(they are batch-idempotent, so cutover is a cursor change) → retire the
stream. The dedup layer and the archive do not change at all.
