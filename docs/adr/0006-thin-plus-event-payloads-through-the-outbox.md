# ADR-006: Domain events carry thin-plus payloads, through a transactional outbox onto the shared stream

- **Status:** Accepted
- **Date:** 2026-08-31
- **Phase:** 5

## Context

Phase 5 gives billing a voice: state changes — a payment settling, a
subscription moving — become domain events other parts of the system react
to. Two decisions had to be made together, because each constrains the
other.

**How events leave the database.** You cannot write to MySQL and a message
broker atomically. Publish-then-commit emits events for states that never
happened; commit-then-publish loses events when the process dies between
the two — and with money involved, "the charge succeeded but nothing
downstream ever heard" is the expensive kind of silent. The transactional
outbox is the standard answer, but it fixes the atomicity problem by
introducing at-least-once delivery, which every consumer must then survive.

**What events say.** The classic tension:

| Style | Payload | Cost |
|---|---|---|
| Thin / notification | `{subscription_id: 7}` | consumer must call back → coupling, load, and a subtler bug below |
| Fat / state transfer | the full aggregate | payload bloat, PII sprayed into every sink, a frozen schema |

The subtler bug is the one that decides it: a thin event forces the
consumer to read CURRENT state, but the event describes a PAST transition.
By the time the consumer calls back, the subscription that "activated" may
already be canceled — the callback answers a different question than the
event asked. Under at-least-once delivery and reordering (both of which we
signed up for above), notification-style events make consumers wrong in
ways that no amount of retrying fixes.

One more force: Phase 2 already built a hardened event pipeline — durable
buffer, consumer groups with crash recovery, an idempotent archive,
dead-lettering — and tech-debt #9 left the reactions consumer running with
an empty map, waiting for exactly these events.

## Decision

1. **Publish through an outbox, in the same transaction as the change.**
   `Outbox::publish()` writes `outbox_messages` and throws if no
   transaction is open — a bare publish is the dual-write bug wearing the
   pattern's name. The three funnels that change billing state
   (`SubscriptionStateMachine`, `ApplyChargeOutcome`, `ApplyRefund`) are
   the only publishers, so an event exists if and only if its state change
   committed. Facts get deterministic idempotency keys
   (`payment:{id}:settled`, `refund:{refund_id}`,
   `subscription:{id}:{status}:{watermark}`); the unique index makes a
   re-decided fact one row.

2. **The relay is just another producer.** `outbox:relay` claims rows with
   `FOR UPDATE SKIP LOCKED` (parallel-safe by construction: peers interleave
   over disjoint rows), submits them to the same `Ingestor` as any client
   SDK, and marks them dispatched — publish inside the claim transaction,
   in that order. A crash between publish and mark re-relays the batch;
   the event id is derived (UUIDv5-style) from the idempotency key, so the
   re-publish is the SAME event and the ingest dedup window collapses it.
   Ingest verdicts are honoured like any producer's: `invalid` dead-letters
   the row (deterministic failure — retry can't fix a shape) for
   `outbox:replay` after a fix; backpressure leaves rows pending, because
   the outbox is itself a durable buffer.

3. **Domain events ride the Phase 2 stream, at operational priority.** No
   second bus. The archive ingests them (billing history lands in the same
   system of record as behavior, for free), the reactions consumer fans them
   out to queued jobs (paying tech-debt #9), and the DAU projection skips
   `billing.*` — server-emitted facts are not user activity. Priority
   `operational` means domain events are never load-shed.

4. **Payloads are thin-plus.** The aggregate's identity plus the few fields
   every consumer needs to act without calling back: ids, `status`,
   `previous_status`, amounts and currency, `charge_id`/`refund_id`,
   `current_period_end`, `store`. Versioned (`schema_version`, upcast via
   the Phase 2 registry) so it can grow additively. Not the full aggregate:
   no card metadata, no user profile — the archive keeps events for
   months, and every field published is a field retained.

5. **Consumers buy exactly-once themselves, each in the cheapest coin.**
   The archive uses `INSERT IGNORE`. Counters — the textbook non-idempotent
   effect — use `ConsumeOnce`: a consumption marker inserted in the SAME
   transaction as the effect, keyed on the deterministic event id, so the
   effect applies exactly once no matter which layer redelivers
   (`billing_metrics` via `ProjectBillingMetric` is the first). Poison
   messages dead-letter at every stage — relay table, stream list
   (`events:replay`), queue (`failed_jobs`) — and replay is safe *because*
   consumers are idempotent: re-delivery to a group that already processed
   costs nothing.

## Consequences

- Kill the relay anywhere — including between publish and mark-dispatched,
  the chaos deliverable — and the system converges: re-publish, dedup,
  markers. Delivery is at-least-once; every EFFECT is exactly-once.
- Consumers never call back into billing tables. They can be moved out of
  this process (or this service) without gaining a database dependency —
  the ADR-001 seam this phase was building toward.
- Delivery latency floors at the relay's poll interval (500ms idle), and a
  dead relay is silent until the backlog ages — hence
  `outbox:status --check` alarming on backlog age, not on the process.
- Thin-plus is a contract: a field consumers need that isn't in the payload
  is a schema bump, not a callback. The discipline is versioning, additive
  changes only — same rules the ingest envelope already lives by.
- Domain events share the behavioral stream's fate: a client-event flood
  delays billing reactions until Phase 6's isolation work (tech-debt #16).
- Consumption markers are dedup memory with a retention (30 days); replay
  of anything older can double-apply (tech-debt #17).

## Alternatives considered

**Fat state-transfer payloads.** Rejected: stale by the time they're read,
they spread PII into a months-deep archive, and every consumer's needs
calcify the schema. The aggregate's current state is one lock away for the
rare consumer that truly needs it; history is what events are for.

**Bare-id notification events.** Rejected for the read-back anomaly above,
and because the callback re-couples every consumer to the publisher's
tables and availability — the exact coupling the outbox was bought to
remove.

**Laravel events + `afterCommit` listeners.** Rejected as the bus: solves
publish-after-commit in-process, but the dispatch itself is not durable — a
worker dying after commit and before dispatch loses the event forever, and
there is no replay, no fan-out to a future second service.

**A separate domain-event stream or queue-jobs-as-transport.** Rejected
for now: a second bus is a second set of groups, lag watchdogs, DLQs and
dashboards, duplicating everything Phase 2 hardened, for isolation Phase 6
delivers properly at the queue layer. The seam is clean — the relay targets
`EventBuffer` — so a dedicated stream is a config-shaped change if #16's
trigger fires.

**CDC / binlog tailing (Debezium) instead of a polling relay.** Rejected at
this scale: sub-poll-interval latency and zero query load, bought with a
connector deployment, schema-registry care, and binlog-format coupling.
The relay is ~100 lines the whole team can read. Tech-debt #18 names the
switch trigger.

## When to revisit

- A consumer starts calling back into billing tables to do its job: the
  payload is too thin — bump the schema, don't normalize the callback.
- Billing reaction lag moves with client-event volume: tech-debt #16, give
  domain events their own stream (the `EventBuffer` seam makes it config).
- Relay latency or poll load starts mattering: tech-debt #18, CDC.
- A second deployable consumes these events: the payload contract becomes a
  public API — freeze it behind a schema registry and consumer-driven
  contract tests before, not after.
- Anyone proposes replaying dead letters older than the consumption-marker
  retention: extend the markers first (tech-debt #17).
