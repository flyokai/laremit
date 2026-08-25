# Laremit

A shared **subscription billing and event backend-core**. Three products — an
EdTech app, a VPN service and an AI tutor — share one user identity, one billing
system and one event pipeline.

The targets it is built to meet:

- 5,000 events/sec sustained, bursting to 20,000, at p99 < 50ms
- Card and in-app purchases that never double-charge
- A provably correct ledger under duplicated, reordered and dropped webhooks

Every non-obvious decision is recorded in [`docs/adr/`](docs/adr/). Every
deliberate shortcut is in [`docs/tech-debt.md`](docs/tech-debt.md).

## Status

**Phase 2 — Event ingestion.** `POST /v1/events` (batch, gzip, envelope-only
validation), Redis Streams buffer with three consumer groups (partitioned
archive, HLL/bitmap projections, domain reactions), dedup, priority-class
backpressure, schema versioning with two live versions, monthly partition
retention, ADR-003. Payments and reconciliation are Phases 3–4.

## Running it

```bash
cp .env.example .env
docker compose run --rm --no-deps app composer install
docker compose up -d --build
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate --seed
```

The `composer install` runs inside the app image but before the stack starts:
the source tree is bind-mounted and the dev image bakes no vendor directory in
(debt #3), so on a fresh clone nothing would boot without it — the app
container fails its own health check and `up` refuses to start Horizon.

| | |
|---|---|
| App | http://localhost:8100 |
| Liveness | http://localhost:8100/up |
| Readiness | http://localhost:8100/health |
| Horizon | http://localhost:8100/horizon |
| MySQL | `127.0.0.1:33100` |
| Redis cache / queue / stream | `127.0.0.1:6301` / `6302` / `6303` |

Ports are set in `.env` and consumed by `compose.yaml`; change them there if they
collide with something else on the host.

The container runs as your host UID so bind-mounted files stay writable. If your
UID is not 1000, set `UID`/`GID` in `.env` before building.

### Two health endpoints, on purpose

`/up` is **liveness** — the PHP process can serve a request. Failing it means
kill and restart.

`/health` is **readiness** — MySQL and all three Redis instances are reachable
*and correctly configured*. Failing it means take this instance out of rotation;
restarting it will not bring MySQL back.

```json
{
  "status": "ok",
  "checks": {
    "database":     { "status": "ok", "duration_ms": 0.78, "detail": { "driver": "mysql" } },
    "redis:cache":  { "status": "ok", "duration_ms": 0.27, "detail": { "maxmemory_policy": "allkeys-lru" } },
    "redis:queue":  { "status": "ok", "duration_ms": 0.14, "detail": { "maxmemory_policy": "noeviction" } },
    "redis:stream": { "status": "ok", "duration_ms": 0.14, "detail": { "maxmemory_policy": "noeviction" } }
  }
}
```

Readiness also *enforces* ADR-002: if the queue or stream instance is running an
evicting `maxmemory-policy`, the probe returns 503 and names the problem. That
is the one misconfiguration — two `REDIS_*_HOST` values pointing at the same
container — which passes every functional test and then silently deletes queued
charges under memory pressure.

## Event ingestion

```
POST /v1/events
Authorization: Bearer $EVENTS_INGEST_TOKEN
Content-Type: application/json
Content-Encoding: gzip            # optional
```

```json
{"events": [{
  "event_id": "f47ac10b-58cc-4372-a567-0e02b2c3d479",
  "type": "video.watched",
  "schema_version": 2,
  "occurred_at": "2026-08-25T10:00:00Z",
  "user_id": 42,
  "product": "edtech",
  "priority": "analytics",
  "payload": {"video_id": "v1", "position_ms": 30000}
}]}
```

Up to 500 events per batch. The response is always 202 with a per-event
status in submission order — `accepted`, `duplicate` (event_id already seen),
`invalid` (with field errors), or `shed` (backpressure; retry after the
`Retry-After` header) — unless the buffer is over the reject watermark, which
is a whole-request 429. Acceptance means *durably buffered*, not processed.

Behind the 202: client-generated `event_id` deduped via `SET NX EX`, a Redis
Stream on the dedicated stream instance, and three consumer groups —

| Group | Does | Idempotency |
|---|---|---|
| `archive` | inserts into month-partitioned `events_archive` | `INSERT IGNORE` on `(event_id, received_at)` |
| `projections` | DAU HyperLogLog + activity bitmap per day | `PFADD`/`SETBIT` are commutative |
| `reactions` | dispatches queued domain jobs (map in `config/events.php`) | reacted-marker; jobs must be idempotent |

Workers run as `php artisan events:work {group}` (one container each in
compose; scale with `--scale worker-archive=3`). `php artisan events:status`
shows depth, per-group lag, dead letters and today's DAU. Old events age out
by monthly partition drop (`events:partitions`, scheduled daily) with
`model:prune` as the portable fallback.

Schema versions: two are live at once (`config/events.php`); consumers
upcast old payloads through `SchemaRegistry`, and the archive stores what
was actually received.

### Load test

```bash
k6 run load/k6-ingest.js                          # 5k events/s for 60s, p99 < 50ms threshold
k6 run -e RPS=100 -e BATCH=200 load/k6-ingest.js  # 20k events/s burst
```

### Chaos runbook — kill a consumer mid-batch

```bash
docker compose kill -s SIGKILL worker-archive     # not SIGTERM: no goodbye
# ingest keeps accepting; entries pile into the dead consumer's PEL
docker compose up -d worker-archive               # replacement claims via XAUTOCLAIM
php artisan events:status                         # pending drains back to 0
```

Accepted count vs `SELECT COUNT(*) FROM events_archive` must match exactly:
no loss (unacked entries are reclaimed), no double-count (idempotent
consumers). The same scenario runs mechanically in CI —
`tests/Feature/Events/EventPipelineTest.php`, "survives a consumer killed
mid-batch".

## Development

```bash
composer check      # pint --test, phpstan level 6, pest
composer lint:fix   # pint
```

Tests run against SQLite in-memory. The event pipeline integration tests
additionally use the stream/cache Redis containers (database 9, test key
prefix — never development data) and skip themselves when the stack is down.

## Layout

```
app/
  Domain/
    Identity/   users — one identity across all products
    Catalog/    products, plans, billing intervals
    Billing/    subscriptions, statuses, stores
    Events/     ingest, stream buffer, consumers, projections (Phase 2)
  Support/
    Health/     readiness checks
docker/
  app/          Dockerfile (FrankenPHP), Caddyfile, php.ini
  mysql/        my.cnf
  redis/        cache.conf, queue.conf, stream.conf — one per workload
docs/
  adr/          architecture decision records
  tech-debt.md  deliberate shortcuts and their payoff triggers
load/
  k6-ingest.js  the 5k events/sec deliverable, as a script
```

Module boundaries are conventions today; Phase 7 adds architecture tests that
fail the build when one module reaches into another's internals.

## Infrastructure notes

**Three Redis instances, not three databases on one.** `maxmemory-policy` is
per-instance, and cache (evictable), queue (must never evict) and event stream
(bounded by app-side trimming) need different answers. Full reasoning in
[ADR-002](docs/adr/0002-redis-topology-and-eviction-policy.md).

**FrankenPHP from day one.** Phase 8 enables Octane worker mode by setting
`FRANKENPHP_CONFIG`, not by changing the app server.

**MySQL runs `READ COMMITTED`.** The billing paths take short row locks, and
REPEATABLE READ's gap locks turn concurrent charges for unrelated users into
lock waits on the same index range. `innodb_flush_log_at_trx_commit = 1` is not
negotiable for a ledger.

**Money is integer minor units plus an ISO-4217 code.** No floats, anywhere.
Phase 3 wraps the pair in a `Money` value object; the columns are already shaped
for it.

**Dates are immutable.** `Date::use(CarbonImmutable::class)` is set globally, and
month arithmetic does not overflow — a subscriber billed on 31 January renews on
28 February, not 3 March.
