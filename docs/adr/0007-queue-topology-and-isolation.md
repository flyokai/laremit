# ADR-007: Three queue lanes on one Redis instance — isolation by supervisor, connection and retry_after, not by hardware

- **Status:** Accepted
- **Date:** 2026-09-01
- **Phase:** 6
- **Amends:** ADR-002's sizing row for `redis-queue` (512mb → 2gb); the
  topology and eviction decisions there stand unchanged.

## Context

Until now every queued job — a charge, a webhook application, a metric
increment, a mock provider's delivery — rode one connection and one
`default` queue, drained by one supervisor. That works right up until it is
the whole outage: the brief's failure story is "payments queued behind a
million analytics events", and with one lane it is not a story, it is the
default behavior. One flood, one slow dependency, or one long job and
everything behind it waits, criticality notwithstanding.

Three distinct forces say "separate", and they separate along different
axes:

- **Criticality.** A charge delayed 30 seconds is an incident; a counter
  increment delayed 30 minutes is invisible. Sharing a worker pool means
  the second starves the first precisely when volume spikes — which is
  precisely when payments matter most.
- **Duration.** `retry_after` — the visibility timeout — is a
  **per-connection** setting, and it must exceed the slowest job on that
  connection: a reserved job reappears after exactly `retry_after` seconds
  whether or not its worker is still running, and nobody checks. One
  hypothetical 5-minute job on a shared connection therefore forces a
  5-minute redelivery delay onto every crashed 2-second charge. This is
  the classic Laravel double-charge bug wearing its other face.
- **Failure profile.** Jobs facing a flaky third party (the PSP, the app
  stores) burn workers retrying against a dependency that is down. Without
  containment, a PSP outage converts the worker fleet into a
  timeout-generation service.

The counterforce is operational budget: this is a one-person system
(ADR-001, ADR-003), and every physical instance added is another process
to monitor, persist and reason about. ADR-002 already gives the queue a
dedicated Redis instance with `noeviction` + AOF; the question is whether
isolation needs more hardware or just more structure.

## Decision

1. **Three lanes as three queue *connections* — `payments`, `events`,
   `bulk` — on the one existing queue instance.** Same Redis, same
   durability; separate connections because `retry_after` is per-connection
   and each lane's is tuned to its slowest job (enforced by
   `QueueTopologyTest` against the supervisors' timeouts):

   | Lane | Carries | timeout | retry_after | rationale |
   |---|---|---|---|---|
   | `payments` | `ChargeJob`, `ProcessWebhookEvent` | 60s | 90s | money movement; tight visibility so a dead worker's charge is re-attempted (same PSP idempotency key) in 90s, not 330 |
   | `events` | `ProjectBillingMetric`, reaction jobs | 30s | 60s | high volume, short jobs; the fastest crash redelivery |
   | `bulk` | mock provider deliveries, batch work, and all of `default` | 300s | 330s | the quarantine for anything slow, so no other lane inherits its visibility timeout |

   The payments connection also sets `block_for` (blocking pop — shaves
   the poll sleep off charge pickup latency) and `after_commit` (no
   payments job visible before its transaction commits, belt to the
   explicit `->afterCommit()` at the dispatch sites).

2. **Three Horizon supervisors, one per lane.** Separate worker pools with
   separate autoscaling bounds is the actual isolation: a million-deep
   events queue can max out `supervisor-events` and take exactly zero
   processes from `supervisor-payments`. Jobs pin their lane in their own
   constructors — the topology is code the job carries, not a convention
   dispatch sites must remember. `supervisor-bulk` also drains `default`,
   which makes the policy for unclassified work explicit: *anything not
   deliberately placed is bulk by definition*, and can only ever be slow —
   never in the way.

3. **Circuit breakers on the provider-facing jobs.** `ThrottlesExceptions`
   (10 failures / 10 minutes) on `ChargeJob`, keyed `by('psp')` and
   counting only `PspUnavailable` — the breaker is shared across all charge
   jobs because the PSP is one dependency, and a decline is an answer, not
   an outage. `ProcessWebhookEvent` carries the same breaker keyed
   `by('stores')` on `StoreUnavailable`: the stores and the PSP fail
   independently, so one tripping must not park the other's jobs. While a
   breaker is open, jobs release untried instead of each burning a worker
   for a connection-timeout's length — the containment for the failure-
   profile force above.

   Corollary: those jobs' retry budgets became **deadlines**
   (`retryUntil`), not attempt counts, because circuit-open releases burn
   attempts without doing work — a `$tries` budget would be spent by the
   breaker itself. `ProcessWebhookEvent` adds `maxExceptions` so a
   genuinely poisonous row still fails fast inside its two-hour window.

4. **Alert on wait time first, depth second.** Depth without wait is a
   queue doing its job. Horizon's `waits` thresholds carry the asymmetry
   that justifies the whole topology — payments alerts at 30s, bulk at
   900s — and `LongWaitDetected` lands as `Log::critical`. Scheduled
   `queue:monitor` is the depth backstop (payments at 500, the volume
   lanes at 100k): it fires while wait is still fine, and it is the only
   watcher that works when Horizon itself is down. Pages arrive in Phase 9
   (tech-debt #19).

## Consequences

- The deliverable holds and was measured: a million synthetic jobs parked
  on the events lane (`queue:flood`, raw-RPUSH by design — Horizon's
  push-time bookkeeping would double the flood's memory) while payment p99
  stayed at its baseline. Numbers in `docs/load-tests.md`.
- Isolation is logical, not physical. All three lanes still share one
  Redis process, its memory ceiling, its AOF fsync cadence and its CPU; a
  flood can no longer steal payment *workers*, but a big enough one
  contends for the *instance*. That is the named residual risk (tech-debt
  #20), accepted against the one-person operational budget.
- `retry_after` is now a per-lane contract with teeth: a job slower than
  its lane's timeout belongs on `bulk`, not in a config bump. The test
  suite fails anyone who widens a timeout past its lane's `retry_after`.
- Deploys must terminate Horizon gracefully with a grace period exceeding
  the longest timeout (330s in compose) — `retry_after` makes a SIGKILL
  survivable, but graceful drain is the difference between "survivable"
  and "clean".
- The breaker trades individual persistence for collective restraint:
  during a PSP outage, charge jobs wait out the decay window rather than
  retrying on their own backoff schedules. Recovery after the window is
  therefore batched — acceptable, since every intent holds its idempotency
  key and a 30-minute deadline before reconciliation takes over.

## Alternatives considered

**Priority ordering on one fleet** (`--queue=payments,events,bulk`).
Rejected: drains in order but shares `retry_after` and the worker pool, so
the duration force is unaddressed and a long bulk job still sits in front
of a charge. Starvation, meanwhile, merely changes victims — with strict
priority it is the *bulk* queue that never runs during a payments burst.
Fine as tie-break *within* a lane, never as the isolation boundary.

**A separate Redis instance per lane** — the module's "ideally separate
Redis". Deferred, not rejected: it adds real isolation (memory, CPU,
persistence) at the cost of two more instances to operate, and nothing
measured yet demands it. The lanes being distinct *connections* already
makes the move a config edit — point `payments` at a new host — which is
exactly the shape a deferred decision should have. Tech-debt #20 names the
trigger.

**SQS (or another managed broker) for the payments lane.** Rejected here:
Horizon is Redis-only, and losing per-lane autoscaling, wait-time metrics
and the dashboard for the most watched lane is a bad trade at this scale.
At real production scale the managed broker's durability story deserves
the fresh look, from an operation that has pages before it has opinions.

**`RateLimited` middleware instead of `ThrottlesExceptions`.** Different
tool: rate limiting throttles *successes* to respect a provider's quota;
the breaker throttles *failures* to stop hammering a provider that is
down. The PSP mock imposes no quota, so only the breaker earns its place —
`RateLimited('psp')` slots in beside it the day a real PSP publishes one.

## When to revisit

- Payments wait time moves with events/bulk depth — the lanes are
  contending for the instance, and tech-debt #20's trigger has fired: give
  `payments` its own Redis (a host swap in one connection block).
- Any lane needs a job slower than 300s: that is a `bulk` redesign
  (chunking, batches) before it is a timeout bump — a 15-minute
  `retry_after` on crashed-job redelivery is a real cost even on the slow
  lane.
- A real PSP with a published rate limit replaces the mock: add
  `RateLimited` beside the breaker and revisit the breaker's thresholds
  against the provider's documented error budget.
- Horizon stops being the operator console (Octane-era consolidation,
  Kubernetes-native workers): the lanes outlive it — they are queue
  connections, not Horizon config — but the supervisor layer would need a
  new home for its autoscaling bounds.
