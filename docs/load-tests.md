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
