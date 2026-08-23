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

**Phase 1 — Foundation.** Docker stack, domain model, health checks, quality
gate, ADR-001 and ADR-002. Event ingestion, payments and reconciliation are
Phases 2–4.

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

## Development

```bash
composer check      # pint --test, phpstan level 6, pest
composer lint:fix   # pint
```

Tests run against SQLite in-memory and need no containers.

## Layout

```
app/
  Domain/
    Identity/   users — one identity across all products
    Catalog/    products, plans, billing intervals
    Billing/    subscriptions, statuses, stores
  Support/
    Health/     readiness checks
docker/
  app/          Dockerfile (FrankenPHP), Caddyfile, php.ini
  mysql/        my.cnf
  redis/        cache.conf, queue.conf, stream.conf — one per workload
docs/
  adr/          architecture decision records
  tech-debt.md  deliberate shortcuts and their payoff triggers
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
