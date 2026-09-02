# Capacity model — 10× the brief

Written after the Phase 8 measurement campaign (docs/load-tests.md). Every
number here is either measured on this stack or arithmetic on a measured
number; the arithmetic is shown so it can be re-run when a number changes.

**The brief's load:** 5,000 events/s sustained, bursts to 20,000, plus the
billing traffic. **This model sizes 10×:** 50,000 events/s sustained, bursts
to 200,000, ~250 purchases/s peak, ~10,000 entitlement reads/s.

**Assumptions, stated because they dominate the answer:**

- Peak ≠ average. Storage math assumes the diurnal average is 20% of peak
  (industry-typical for consumer apps); latency math assumes peak.
- Measured unit rates come from one 28-core box running *everything* —
  generator, app, MySQL, three Redis, Horizon. Dedicated hardware can only
  improve them; the model still discounts where the co-location plausibly
  flattered a number.
- p99 budgets stay the brief's: ingest < 50ms, and payment p99 in the
  20–30ms band it holds today.

## Measured unit rates (the inputs)

| Unit | Measured | Source |
|---|---|---|
| App container, ingest | ≥ 20,000 events/s at p99 12.2ms (no knee found) | Phase 8 burst probe |
| App container, entitlement reads | ≥ 1,000 req/s at p99 2.2ms (no knee found; generator-limited) | Phase 8 probe |
| App container, payment POSTs | 25/s at p99 21.2ms, nowhere near saturation | Phase 8 rung D |
| Archive consumer (one worker) | ≥ 5,000 events/s drained in real time (batched `INSERT IGNORE`, ≤200 rows/stmt) | Phase 2 + every Phase 8 rung |
| Events-lane queue worker | ≈ 180 jobs/s (1,100 jobs/s at 6 workers) | Phase 6 flood drain |
| Queue Redis, enqueue | 420k jobs/s pipelined; 1M-job backlog ≈ 666MB | Phase 6 flood |
| Stream Redis | 20k events/s XADD+consume at 63MB resident (trimmed) | Phase 8 burst |
| Worker RSS | ~505MB flat under load with `MAX_REQUESTS=1000` recycles | Phase 8 |

## The 10× arithmetic

**Ingest tier — 3 containers, 10 for the burst.** 50k events/s ÷ 20k proven
per container = 2.5 → **3 containers** sustained (each under half its proven
rate), **10 during a 200k burst** — or fewer, deliberately, because
backpressure is load-shedding by priority class and held p99 at 10.5ms while
shedding 68% of a flood (Phase 8). The stateless tier is the easy one:
`docker compose up --scale` shaped, no coordination.

**Entitlement reads — the same 3 containers.** 10k req/s ÷ 1,000 proven =
10 container-equivalents of *measured* capacity, but the probe never found
the knee and each read is one indexed `EXISTS` + one `value()` — the honest
constraint is MySQL read QPS (~20k/s of this shape), answered with read
replicas or a short-TTL cache before more app containers.

**Event consumers — 10 archive workers, and the first real re-architecture.**
50k/s ÷ 5k per archive worker = **10 replicas** (`--scale worker-archive=10`;
consumer names are host:pid, the group absorbs them). The workers scale; the
**storage does not**: 50k events/s sustained at 20% average duty =
~864M rows/day ≈ 260GB/day into a single InnoDB table. Monthly partitions and
`MassPrunable` manage 1×; at 10× the archive must leave the OLTP database —
columnar store or object-storage batches, written from the same consumer
seam. This is the ADR-003 migration trigger territory, and it fires *before*
the buffer does.

**Stream buffer — shard by product at 10×, per ADR-003's own trigger.** One
Redis stream instance handled 20k/s at 3% of its memory ceiling; 50k/s
sustained is ~2.5× that ops rate on a single-threaded server, with a 200k
burst 4× again. Redis streams shard trivially by key (product), the consumer
groups already scale horizontally — but at the point of sharding streams,
managing partitioned consumption is Kafka's actual job. ADR-003 names
sustained >20k/s with multi-consumer fan-out as the trigger; 10× is squarely
past it. Budget the migration, don't stretch the shard.

**Billing — 13 payment-lane workers, and the PSP is the ceiling, not us.**
250 purchases/s × (one ChargeJob ≈ PSP RTT ~40ms + DB work) ≈ 10 concurrent
charges in flight → **13 workers at 75% utilization**. MySQL's share is
trivial (250/s of short disjoint-row transactions; the ledger is append-only
with unique keys); the idempotency and webhook tables grow at 21M rows/day
and need their pruning windows confirmed. The real 10× billing risks are
external: PSP rate limits and webhook redelivery storms — which is what the
`ThrottlesExceptions` breakers and the reconciliation sweeps are for, and
they are load-independent.

**Queue Redis — split the lanes onto instances.** ADR-007's 2GB ceiling was
set by observing 1M jobs + Horizon metering at 1.23GB. 10× backlog math says
a bad hour could want ~7GB on one instance; tech-debt #20 already names the
split (payments lane to its own instance first) as a config edit by design.

**MySQL (billing core) — vertical headroom is real, the archive must go.**
Once the event archive stops writing 260GB/day into InnoDB, the remaining
billing OLTP at 10× is hundreds of small transactions/s against short
indexed rows — one primary with a read replica for entitlements/reporting
covers it. `READ COMMITTED` + disjoint user-row locks means contention does
not scale with users (Phase 7's concurrency suite is the proof mechanism).

## What binds first, in order

1. **Archive storage in InnoDB** — re-architect at ~2–3× sustained, before
   any compute limit is reached.
2. **Single stream shard** — ADR-003's Kafka trigger, at ~4× sustained.
3. **Queue Redis memory under backlog** — lane-per-instance split, ADR-007.
4. **MySQL read QPS for entitlements** — replica or cache, cheap.
5. App/worker compute — last, and linear when it comes.

## Fleet summary at 10×

| Tier | Today | 10× |
|---|---|---|
| App containers (Octane workers) | 1 | 3 sustained / 10 burst, behind an LB |
| Archive / projection / reaction consumers | 1 each | 10 / 4 / 4 |
| Horizon queue workers | auto ≤ 6 events + 1 payments | 13 payments, 20 events/bulk, split supervisors |
| Redis (cache / queue / stream) | 1 / 1 / 1 | 1 / 2 (lane split) / sharded or Kafka |
| MySQL | 1 | 1 primary + 1 replica; archive moved out |

The honest one-liner for the interview: *the stateless tiers 10× by
arithmetic; the two things that don't are the archive table and the single
stream shard, both already have named triggers in the ADRs, and both fail
loudly (partition growth, `events:check-lag`) long before they fail hard.*
