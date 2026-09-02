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

**Phase 7 — Testing & quality.** Larastan at level 7 with no baseline; an
architecture suite (`tests/Arch`) that fails the build on boundary
violations; query-count budgets on the hot endpoints (ingest is provably
zero DB queries at any batch size); the chaos tests as first-class CI jobs
with env-overridable seeds; and a real-parallelism concurrency suite — 20
simultaneous HTTP requests against a served app on MySQL → exactly one
charge — which found and fixed a genuine double-charge race on first run
(five settled charges on one subscription; see docs/ci.md). CI splits
fast-on-push from adversarial-nightly: the nightly MySQL suite caught three
MySQL-only schema bugs the day it was born. Earlier: Phase 4 below.

**Phase 4 — Webhooks, reconciliation, IAP.** The webhook edge rebuilt as
verify → persist raw → 200 → queue, with a signed-timestamp tolerance and a
provider-event-id unique key; refunds and revocation; stale-transition
rejection by the provider's clock; mock Apple App Store (ASSN v2 as
ES256-signed JWS) and Play Store (RTDN over Pub/Sub) that own subscription
state the way the real ones do (ADR-005); and hourly bidirectional
reconciliation that converges state after dropped webhooks and reports how
many discrepancies it fixed. Outbox and domain events are Phase 5.

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

## Payments

```
POST /v1/payments            Authorization: Bearer $BILLING_API_TOKEN
Idempotency-Key: <client-generated>
{"user_id": 1, "product": "edtech", "plan": "monthly"}
```

Answers 202 — charging is asynchronous (`ChargeJob`, retried with backoff).
Poll `GET /v1/payments/{id}`; ask `GET /v1/entitlements?user_id=&product=`
for the only entitlement answer that exists.

Idempotency is layered per ADR-004, and the layers are deliberately
redundant:

1. **Inbound** — `Idempotency-Key` header: atomic claim, stored-response
   replay (`Idempotency-Replayed: true`), 422 on key reuse with a different
   body, 409 while the original is running.
2. **PSP boundary** — one `psp_idempotency_key` per intent for life; every
   retry reuses it, so N attempts are at most one charge. A PSP timeout is
   treated as *unknown*, never as failure.
3. **Ledger** — every outcome funnels through `ApplyChargeOutcome` under a
   row lock, and `ledger_entries` unique keys are the database-enforced
   floor beneath it.

The ledger is double-entry and append-only (updates throw): a successful
charge books `+psp_cash / -revenue`, every transaction sums to zero, and
`php artisan billing:ledger` prints the trial balance and fails if the books
are off by a minor unit.

### The mock PSP

`/mock-psp/*` (local tooling, `MOCKPSP_ENABLED`) is built to be hostile:
idempotency keys honoured byte-exactly, webhooks delivered late, duplicated,
out of order and — `webhook.drop_rate` — sometimes never; outcomes forced by
amount convention:

| `amount_minor % 100` | Outcome |
|---|---|
| 1 | **timeout, but the charge succeeds** — the caller must not double-charge |
| 2 | declined |
| 3 | timeout, nothing recorded — settles only by reconciliation |
| other | succeeds (or random per `POST /mock-psp/config` rates) |

It also has the provider's read side — `GET /mock-psp/v1/charges?since=`
and `?idempotency_key=` — which is what reconciliation asks, and refunds:
`POST /mock-psp/v1/charges/{id}/refunds {amount_minor?}` fires a
`charge.refunded` webhook per refund.

The chaos deliverable — random timeouts, duplicated and reordered webhooks,
ledger exactly correct — runs as `tests/Feature/Billing/PaymentChaosTest.php`.

## Webhooks

Every provider-facing endpoint does the same four things, in this order:

```
verify   HMAC over "t.<raw body>" (X-Psp-Signature: t=…,v1=…), ±5 min;
         Apple: ES256 JWS against the pinned key; Google: the Pub/Sub token
persist  webhook_events, raw bytes, UNIQUE(provider, provider_event_id)
200      "stored", not "understood" — a slow handler manufactures duplicates
queue    ProcessWebhookEvent — dispatched when the row is still `pending`,
         NOT when it was "just inserted": the difference is a process that
         died between INSERT and dispatch, which the provider's retry heals
```

The handlers apply idempotently: charge outcomes through the Phase 3
funnel, refunds through `ApplyRefund` (contra-revenue lines keyed on the
refund id; a full refund revokes the subscription), store notifications
through the projector below. Every row records its verdict (`applied`,
`duplicate`, `stale`, `conflict`, `ignored`, …) so a delivery's fate is a
query, not a log search.

## In-app purchases (Apple / Google)

The store owns the subscription; we hold a projection (ADR-005).

```
POST /v1/iap/apple/notifications          App Store Server Notifications V2
POST /v1/iap/google/notifications?token=  Real-Time Developer Notifications (Pub/Sub push)
POST /v1/iap/{apple|google}/sync          "restore purchases" — Bearer $BILLING_API_TOKEN
                                          {"user_id": 1, "identifier": "<originalTransactionId | purchaseToken>"}
```

Apple's signed payload *is* the store speaking: verify, derive the
absolute state from the signed transaction + renewal info, project. Google's
RTDN is a hint: dedupe on `messageId`, **re-fetch** the purchase from the Play
Developer API, project *that*, acknowledge. Both roads produce one
`StoreSubscriptionSnapshot` and pass one guard — the store's clock against
the row's `last_event_at` — so an older delivery loses whatever order it
arrived in. The sync endpoint never grants from the claim: it re-fetches
the identifier from the store and refuses one the store links elsewhere.

The mock stores (`/mock-stores/*`, `MOCKSTORES_ENABLED`) stand in for a
user tapping "subscribe" on a phone, and for the stores' server APIs:

```bash
TOKEN=$(docker compose exec app php artisan tinker --execute='echo App\Domain\Identity\Models\User::first()->app_account_token;')
curl -s localhost:8100/mock-stores/apple/purchases -H 'Content-Type: application/json' \
  -d "{\"product_id\":\"com.laremit.edtech.monthly\",\"app_account_token\":\"$TOKEN\"}"
curl -s -X POST localhost:8100/mock-stores/apple/subscriptions/<originalTransactionId>/cancel   # renew|cancel|resume|expire|fail-payment|recover|refund|revoke
curl -s localhost:8100/mock-stores/google/purchases -H 'Content-Type: application/json' \
  -d "{\"product_id\":\"com.laremit.vpn.monthly\",\"obfuscated_external_account_id\":\"$TOKEN\"}"
curl -s -X POST localhost:8100/mock-stores/google/purchases/<purchaseToken>/on-hold           # renew|cancel|restart|on-hold|grace|recover|expire|revoke|pause
```

Store product ids are `com.laremit.{product-slug}.{plan-slug}`. The mock
App Store signs with a committed dev key (`MOCK_APPLE_SIGNING_KEY`) whose
public half the app pins (`APPLE_ASSN_PUBLIC_KEY`); nothing real ever signs
with it.

## Reconciliation

> Webhooks are an optimization; reconciliation is the source of truth.

`php artisan billing:reconcile` (hourly from the scheduler, 26-hour
overlapping window) runs four sweeps and persists the tally in
`reconciliation_runs`:

| Sweep | Direction | Fixes | Pages on |
|---|---|---|---|
| `PspChargeSweep` | theirs → ours | an intent the PSP settled but no webhook told us about; a refund we never booked | a charge for an intent we don't know; a settled intent the PSP contradicts (terminal wins, ADR-004) |
| `StuckIntentSweep` | ours → theirs | an intent stuck `processing` that the PSP has a charge for under our key | one the PSP has never heard of, after `max_recovery_attempts` re-dispatches |
| `StoreSubscriptionSweep` | store → us | any live Apple/Google row whose projection drifted | a row the store has no record of |
| `PendingWebhookSweep` | — | re-queues a persisted delivery still `pending` past 5 min | — |

It alerts on the *count* of what it could not fix (`Log::critical`), warns
on what it did fix — a reconciliation that quietly repairs drift every hour
is hiding a broken webhook path — and exits non-zero when anything is
unresolved.

### The Phase 4 deliverable — drop 20% of webhooks, converge in one run

```bash
curl -s -X POST localhost:8100/mock-psp/config    -H 'Content-Type: application/json' -d '{"webhook":{"drop_rate":0.2}}'
curl -s -X POST localhost:8100/mock-stores/config -H 'Content-Type: application/json' -d '{"delivery":{"drop_rate":0.2}}'
# ...drive purchases, refunds and store lifecycles (above), watch some intents stay `processing`
# and some store rows fall behind; then:
docker compose exec app php artisan billing:reconcile
```

The run prints what it scanned, what disagreed, and `Fixed: N` — exactly the
discrepancies the drops caused. A second run prints `No discrepancies`. The
same scenario runs mechanically, seeded, as
`tests/Feature/Billing/ReconciliationChaosTest.php`: 30 PSP purchases in a
forced mix plus refunds, 10 App Store and 10 Play Store subscriptions with a
lifecycle each, a fifth of every notification dropped and a third of the
rest duplicated and shuffled — one run converges everything it can see, and
the client's restore call brings in the one thing it cannot (a store
purchase whose every notification was lost).

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
composer check      # pint --test, phpstan level 7, pest
composer lint:fix   # pint

./vendor/bin/pest --group=chaos         # the chaos proofs alone
CHAOS_SEED=12345 ./vendor/bin/pest --group=chaos   # replay a nightly seed
./vendor/bin/pest --group=concurrency   # 20 parallel requests, one charge
```

Tests run against SQLite in-memory. The event pipeline integration tests
additionally use the stream/cache Redis containers (database 9, test key
prefix — never development data) and skip themselves when the stack is down;
the concurrency suite likewise boots its own served app against a throwaway
MySQL database (`laremit_concurrency`) and skips when MySQL is unreachable.
The nightly CI job runs the entire suite with MySQL as the default
connection. The split, the measured times, and what each tier has caught:
docs/ci.md.

## Layout

```
app/
  Domain/
    Identity/   users — one identity across all products
    Catalog/    products, plans, billing intervals
    Billing/    money, payment intents, ledger, state machine, entitlements,
                webhooks (edge + handlers), stores (IAP projection), reconciliation
    Events/     ingest, stream buffer, consumers, projections (Phase 2)
  MockPsp/      the pretend payment provider (hostile on purpose)
  MockStores/   the pretend App Store and Play Store (they own the truth)
  Support/
    Health/     readiness checks
    Idempotency/ inbound idempotency records (ADR-004 layer 1)
    Jws/        ES256 JWS compact serialization (no library, RFC 7515/7518)
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
