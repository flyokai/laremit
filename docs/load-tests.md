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

## Phase 8 deliverable — 2026-09-02

**Target:** a baseline → optimization ladder with every delta recorded, the
Octane (FrankenPHP worker mode) before/after, and the interleaved-user leak
demo — armed and disarmed. The 10× numbers derived from these runs live in
docs/capacity-model.md.

**Setup (honest caveats, same box as every phase):** dev Docker image,
bind-mounted source, single app container; MySQL, all three Redis instances,
Horizon and the load generator all sharing one 28-core machine. The A/B
switch is `FRANKENPHP_CONFIG`/`FRANKENPHP_INDEX` (compose.yaml) — same image,
classic mode when both are empty. Harnesses: `load/ingest-p99.php` (the k6
script's PHP twin), `load/payments-p99.php`, `load/entitlements-p99.php`.
Every rung: 60s per endpoint at constant arrival — ingest 50 req/s ×
100-event batches (5,000 events/s), payments 25 req/s of real purchases
against fresh pre-seeded users, entitlements 100 req/s alternating a
subscribed and an unsubscribed user (the identity assertion runs in every
rung, not just the demo). Between rungs: consumer lag drained to 0 and the
stream trimmed below the shed threshold, for the reason the incident note
below explains.

### The ladder

| Rung | Server config | entitlements p50/p99 | ingest p50/p99 | payments p50/p99 |
|---|---|---|---|---|
| A | classic FPM-style, no caches, `validate_timestamps=1` | 6.2 / 11.3 | 11.3 / 16.1 | 16.3 / 25.2 |
| B | A + `artisan optimize` (config/route/event caches) | 4.1 / 8.0 | 9.3 / 14.2 | 14.5 / 26.5 |
| C | B + `opcache.validate_timestamps=0` (via `php_ini` in `FRANKENPHP_CONFIG`) | 3.5 / 6.7 | 8.3 / 13.4 | 12.9 / 20.8 |
| D | **Octane worker mode** (caches present) | **1.3 / 2.7** | **6.7 / 11.7** | **8.9 / 21.2** |
| D′ | worker mode, caches cleared (committed dev default) | 1.4 / 2.7 | — | — |

All latencies in ms; every run 0 failed requests, 0 shed events, identity
check clean. Checklist items that came before the ladder, verified rather
than measured: no N+1 on any load-tested path (the Phase 7 query-count
budgets pin ingest at 0 queries and the billing paths at fixed counts), and
indexes covered by the same budgets.

**Reading the ladder.** Worker mode's win is exactly proportional to how much
of a request was bootstrap: the cheap entitlement read drops 79% at p50
(6.2 → 1.3ms), ingest 41%, and the payment POST 45% at p50 — but payment
**p99 barely moves** (25.2 → 21.2ms). That flat p99 is the module's
failure-mode table measured for real: the payment tail is MySQL row work and
locks, and no app-server change can buy it back. D′ = D confirms the same
logic from the other side: under worker mode, `optimize` only accelerates
worker *boot* — per-request cost is unchanged, so the dev stack ships
uncached and production baking `optimize` into the image stays a Phase 9
image-build concern (tech-debt #3).

### Worker-mode capacity probes (single container)

| Probe | Result |
|---|---|
| Ingest burst, 200 req/s × 100 events × 20s | **19,991 events/s, p99 12.2ms**, 0 shed — the brief's 20k burst target, one container, 4× p99 headroom |
| Entitlements 400 req/s × 30s | p99 2.0ms |
| Entitlements 1,000 req/s × 15s | p99 2.2ms — **no knee found**; past this the co-located load generator is the suspect, not the server |
| Worker RSS across ~35k requests | 508MB → 503MB, flat; `MAX_REQUESTS=1000` recycles happened throughout and are invisible at p99 |
| Ingest flood past the shed threshold (600k events into a 500k-deep stream) | 407k shed by priority class, p99 held at 10.5ms — backpressure degrading throughput, never latency |

### The interleaved-user leak demo (ADR-008)

One command arms the planted leak (a warmed singleton holding the acting
user), one plain `up -d` disarms it:

| State | Result of `entitlements-p99.php --users=<subscribed>,<unsubscribed>` at 100 req/s × 15s |
|---|---|
| `OCTANE_DEMO_CROSS_REQUEST_LEAK=true` | **CROSS-REQUEST LEAK: 750 of 1,500 responses answered about the wrong user** — every request for the unsubscribed user answered as the subscribed one, `has_access=true` included |
| disarmed (scoped binding, the committed default) | identity check: every response matched the requested user |

The same pair of facts is pinned hermetically in CI by
`tests/Feature/Octane/InterleavedUsersTest.php`, which drives the real Octane
`Worker` through both bindings in a child process.

### Incident note: the stale-config worker (unplanned, kept)

Rung B's first attempt was measured against a pipeline quietly poisoning
itself: the long-running `worker-reactions` container predated the session
(booted while a since-cleared config cache was on disk), and its in-memory
config had no `events` queue connection — so every `billing.*` event from the
payments runs failed dispatch 7 times and dead-lettered. 7,502 dead letters
replayed cleanly after a worker restart: `billing_metrics` came out at
**exactly 3,000 payments_succeeded / 3,000 activations against 3,000
succeeded intents** — the Phase 5 consumption markers absorbed 7 failed
deliveries plus a full replay with zero double-counts. Two lessons kept:
restart *every* long-running container after code or config changes, not
just Horizon; and measurement hygiene — the first rung-B numbers also sat on
top of an undrained 600k-entry archive backlog, which is why the methodology
above drains and trims between rungs. The contaminated numbers (payments p99
250.6ms) were discarded and the rung re-run clean.
