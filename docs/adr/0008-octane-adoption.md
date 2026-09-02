# ADR-008: Octane worker mode on FrankenPHP — adopted as an audit with numbers, not a config flag

- **Status:** Accepted
- **Date:** 2026-09-02
- **Phase:** 8

## Context

The app has run FrankenPHP since Phase 1 — deliberately, so that worker mode
would one day be an environment variable and not a server migration. Classic
mode boots the framework for every request: autoload, config, container,
providers, routes, then the actual work. On the cheapest endpoint in the API
(the entitlement read) that ceremony was most of the request; on the ingest
edge it was a meaningful slice of a 50ms p99 budget.

The counterforce is the reason most Laravel codebases stay on FPM semantics:
almost all PHP ever written assumes the runtime forgets everything between
requests. A long-lived worker keeps three kinds of state alive that FPM
destroyed for free — static properties, container singletons, and open
connections — and the failure mode of the first two is not a crash but
**another user's data in the response**. That is a correctness bug wearing a
performance feature's clothes, in a system whose whole brief is "never
double-charge anyone" and whose entitlement endpoint gates paid access.

Two facts, established empirically in this phase (the experiment is pinned
as `tests/Feature/Octane/InterleavedUsersTest.php`), sharpen the folklore:

- **Octane sandboxes lazily-resolved singletons.** Each request is served by
  a clone of the booted app; a singleton first resolved *during* a request
  lives in that sandbox and dies with it. The folklore "any singleton
  holding request state leaks" is wrong.
- **What actually leaks is process-global or boot-resolved state:** static
  properties, and any object resolved in the *base* application — at boot,
  via `octane.warm`, or inside a provider — which every sandbox then shares
  by reference. And `scoped()` is immune even there: Octane's
  `FlushTemporaryContainerInstances` forgets scoped instances from the base
  app after every request.

So the hazard is narrow, auditable, and has a one-word fix — which is
exactly what makes adopting Octane an audit rather than a leap.

## Decision

1. **FrankenPHP native worker mode is the default app server**, configured
   entirely from compose.yaml: `FRANKENPHP_CONFIG` registers
   `public/frankenphp-worker.php` (a thin shim over Octane's vendor worker
   loop) and `FRANKENPHP_INDEX` routes requests to it. Both empty = classic
   mode from the same image — the permanent A/B switch behind the
   before/after table. There is no `octane:start` process; the web server
   is the worker supervisor.

2. **Request state gets `scoped()`, never `singleton()` — enforced by a
   test, not a convention.** The one request-scoped service this codebase
   has (`ActingUser`) is bound `scoped()`, and the interleaved-user test
   drives the real Octane `Worker` through both the correct binding and the
   planted wrong one (`OCTANE_DEMO_CROSS_REQUEST_LEAK` arms singleton +
   warm) — asserting the leak is *caught*, so the detector itself is proven.
   The live twin: `load/entitlements-p99.php` asserts response identity on
   every perf run, so every future load test is also a leak canary.

3. **Workers recycle at `MAX_REQUESTS=1000`** — any slow leak becomes a
   bounded one — with Octane's 50MB GC threshold underneath. Measured: RSS
   flat at ~505MB across 35k requests, recycles invisible at p99.

4. **The audit below is the adoption gate**, run before enabling worker
   mode and re-run when its greps change.

### The audit checklist (and this codebase's findings)

| # | Check | How | Finding here |
|---|---|---|---|
| 1 | Static properties holding mutable state | `grep -rn "static \$" app/` and review every hit | One: `StoreClock::$last` — mock-stores monotonic clock, never deployed; persistence across requests strengthens its guarantee rather than leaking |
| 2 | Singletons capturing request/auth/tenant state | Review every `singleton()`/`scoped()` binding | All singletons hold config scalars and *manager/factory* objects (Redis factory, HTTP factory), never a resolved connection or request datum; `ActingUser` is the deliberate scoped counter-example |
| 3 | `octane.warm` entries | Anything warmed is shared by every sandbox — must be stateless or have a flush listener | Framework defaults only; the demo splice is the documented exception |
| 4 | Runtime `config([...])` mutation | grep for `config([` writes | None |
| 5 | Container bindings registered outside providers | grep controllers/jobs for `bind(`/`singleton(` | None |
| 6 | Third-party static caches | Read Octane's `Listeners/Flush*` — the hazard list as code | Stock listener set covers everything in use (`once()`, Monolog, scoped) |
| 7 | Connection staleness across idle gaps | Octane reconnects db/redis; verify under `wait_timeout` | Managers (not connections) are injected everywhere; phpredis + mysql defaults verified on the live stack |
| 8 | Superglobal assumptions | grep for `$_SERVER`/`$_GET`/`$_POST` in app/ | None; everything goes through `Request` |
| 9 | Memory ceiling | Sustained-load RSS watch + `MAX_REQUESTS` | Flat; see docs/load-tests.md |
| 10 | The interleaved-user test, armed and disarmed | The only check that catches what the greps miss | 750/1,500 wrong-user responses armed; 0 disarmed |

## Consequences

**Bought:** entitlements p50 6.2ms → 1.3ms (p99 11.3 → 2.7); ingest p99
16.1 → 11.7ms; payment p50 16.3 → 8.9ms; one container absorbing the
brief's 20k events/s burst at p99 12.2ms (docs/load-tests.md, Phase 8).
Also bought: the capacity model's app-tier arithmetic — 10× ingest is three
containers, not eleven.

**Paid:**

- Payment **p99** moved 25.2 → 21.2ms only — the tail is MySQL row work,
  and no app server can refund it. Octane is not a database optimization;
  anyone reading the p50s as "4× across the board" is reading them wrong.
- Dev ergonomics: the app container no longer picks up code edits per
  request — `docker compose restart app` after changes, same as the workers
  and Horizon always needed (tech-debt #24).
- A standing discipline: every new request-scoped service must be
  `scoped()`, and audit items 1–5 re-run on review. The greps are cheap;
  forgetting them is not, because the failure mode is silent and
  user-facing.
- The demo flag ships in the codebase (config/octane.php, tech-debt #23) —
  a deliberately arm-able bug, justified because a leak detector that has
  never seen a leak proves nothing.

## Alternatives considered

- **Stay classic.** Rung C (optimize + opcache tuning) got entitlements to
  3.5ms p50 — respectable, and zero state-hygiene discipline needed. Rejected
  because the brief's latency budgets at 10× make per-request bootstrap the
  single largest recoverable cost, and the audit surface here is small *now*
  — adopting the discipline at Phase 8 scale is cheap; retrofitting it onto
  a grown codebase is the expensive version of this ADR.
- **Swoole / OpenSwoole.** More features (coroutines, tables, ticks) for an
  extension dependency and a second concurrency model to reason about.
  Nothing in this system needs coroutines; the queue lanes already own
  concurrency.
- **RoadRunner.** Mature, but a second binary and supervisor next to a
  FrankenPHP that has been in the stack since Phase 1 for exactly this
  moment.
- **A dedicated async service (AMPHP-style) for the hot endpoints.** The
  strongest rejected option: it would beat Octane on concurrent-I/O fan-out,
  but nothing here is I/O-concurrency-bound — the hot paths are one Redis
  pipeline or a couple of indexed queries. An event loop buys nothing
  measurable and costs a mental model in which one blocking call stalls
  every request in the process.

## When to revisit

- A cross-request leak reaches production despite the audit and the test —
  the discipline failed; either strengthen the rig (property-test the
  interleaving) or retreat to classic mode, which remains one variable away.
- Worker RSS stops being flat under `MAX_REQUESTS` recycling — something
  accumulates faster than the ceiling; find it or lower the ceiling.
- A genuinely I/O-fan-out endpoint appears (aggregating N upstreams per
  request) — that is the async-service trigger, not an Octane tuning
  problem.
- Laravel/Octane changes sandbox semantics — the interleaved test's
  planted-leak half is the tripwire: it *fails* (leak not caught) the day
  boot-resolved singletons stop being shared.
