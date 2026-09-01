# Load test log

Every run recorded here, with its caveats — a performance number without its
methodology is a rumor. The canonical tool is `load/k6-ingest.js`; runs below
that predate k6 on the dev box used an equivalent constant-arrival-rate
`curl_multi` harness (unique 100-event batches per request, keepalive).

## Phase 2 deliverable — 2026-08-25

**Target:** 5,000 events/sec sustained, p99 < 50ms, and a consumer killed
mid-batch with no loss and no double-counting.

**Setup (honest caveats):** dev Docker image — FrankenPHP classic mode (no
Octane worker mode until Phase 8), bind-mounted source, opcache with
`validate_timestamps=1`; single app container; load generator on the same
machine competing for CPU; MySQL and all Redis instances on the same box.
Production-shaped numbers get re-measured in Phase 8 against the baseline
table there.

**Run:** 50 req/s × 100-event batches × 60s = 300,000 events, plus a chaos
injection: `SIGKILL` (not SIGTERM) of the archive worker at t+15s, replacement
started at t+25s.

| Metric | Result | Target |
|---|---|---|
| Sustained rate | 5,001 events/sec (3,000 requests / 60.0s) | 5,000 |
| p50 / p90 / p95 | 11.5 / 12.8 / 14.2 ms | — |
| **p99** | **17.1 ms** | < 50 ms |
| max | 32.0 ms | — |
| Non-202 responses | 0 of 3,000 | 0 |
| Accepted events | 300,000 of 300,000 | — |

**Chaos outcome:** with 310,001 events ingested in total that day (warmup +
run + smoke), `events_archive` held exactly **310,001 rows, 310,001 distinct
event_ids** after drain; every consumer group at pending 0 / lag 0; dead
letter empty. The killed worker's batch was reclaimed by its replacement via
`XAUTOCLAIM` (both consumer names visible in the group afterwards) and
re-applied under `INSERT IGNORE` — no loss, no double-count.

**Headroom notes:** at 5k/s the archive worker's batched `INSERT IGNORE`
(≤200 rows/statement) drained in real time; stream depth stayed flat during
steady state and recovered within seconds of the worker restart. The p99 gap
(17ms vs 50ms budget) is the room the 20k/s burst target will spend.

## Phase 6 deliverable — 2026-09-01

**Target:** flood the events queue with 1,000,000 jobs; prove payment p99 is
unaffected (ADR-007's isolation claim, measured).

**Setup (same caveats as Phase 2):** dev Docker image, single app container,
load generator (`load/payments-p99.php`, curl_multi constant-arrival) on the
same machine, MySQL and all Redis instances on the same box. Every payment
request is a real purchase against a pre-seeded fresh user (raw-insert
seeding, ids 6–40005): unique `Idempotency-Key`, 202 with an intent id, a
`ChargeJob` on the payments lane, mock-PSP charge over HTTP, webhook back
through the bulk lane, `ProcessWebhookEvent` on the payments lane.

**Run:** baseline 25 req/s × 60s → `queue:flood 1000000` → identical 25 req/s
× 60s while the events lane held a ~1M backlog.

| Metric | Baseline | During 1M-job flood |
|---|---|---|
| Requests (all 202) | 1,500 / 1,500 | 1,500 / 1,500 |
| p50 / p90 / p95 | 16.2 / 19.5 / 20.6 ms | 17.5 / 21.7 / 23.5 ms |
| **payment p99** | **23.1 ms** | **28.2 ms** |
| max | 107.8 ms | 102.7 ms |
| events queue **wait** | ~0 | **727 s** |
| payments queue **wait** | 0 s | **0 s** |
| intents settled | all | **all** (3,007 succeeded, 0 in flight) |

The flood itself: 1,000,000 `SyntheticEventJob` payloads pushed in **2.4s**
(420k jobs/sec, pipelined multi-value `RPUSH` of a real payload template —
see `queue:flood`'s docblock for why it bypasses the Queue facade). Queue
Redis peaked at **666 MB** — past the old 512 MB ceiling, which is why
ADR-007 amends it to 2 GB. Horizon autoscaled `supervisor-events` to its max
6 workers (drain ≈ 1,100 jobs/s) while `supervisor-payments` sat untouched
at 1 process with zero wait; the ~5ms p99 shift is the two extra
worker-fleets' CPU on a shared box, not queueing.

**Alerting outcome:** both watchdogs fired unattended, for the right lane
and only that lane — the scheduled `queue:monitor` logged critical
(`QueueBusy` on `events:events`) every minute from 17:50:01 until depth
fell back under 100k at 18:05, and Horizon's `LongWaitDetected` logged
critical at wait 773s on `events:events`. `payments:payments` (thresholds:
500 deep / 30s wait) stayed silent throughout, because it was never behind.

**Drain:** the full backlog cleared in ~18 minutes (≈920 jobs/s average)
with **zero** `failed_jobs`. Queue Redis read 1.23 GB just after the drain
— payloads gone, an hour of Horizon's per-job metering still resident —
which is the observation that retires the old 512 MB ceiling for good. The
breaker never opened (the PSP was healthy — its behavior is pinned by
`tests/Feature/Queue/CircuitBreakerTest.php`).
