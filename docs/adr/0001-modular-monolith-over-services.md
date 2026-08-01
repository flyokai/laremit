# ADR-001: A modular monolith, not services

- **Status:** Accepted
- **Date:** 2026-08-01
- **Phase:** 1

## Context

The brief is a shared backend-core for three products (an EdTech app, a VPN, an
AI tutor) that share one identity, one billing system and one event pipeline.
The load target is 5,000 events/sec sustained with bursts to 20,000, plus card
and in-app purchases that must never double-charge.

The obvious reading of "shared backend-core" is a set of services: an identity
service, a billing service, an event service. That is what the phrase usually
means in job descriptions, and it is what an interviewer expects to hear
defended one way or the other.

There are three forces actually in play:

1. **Correctness.** The ledger must be provably exact under duplicated,
   reordered and dropped webhooks. Every one of those guarantees is cheapest
   when the state change and the record of it commit in the same database
   transaction.
2. **Blast radius.** A flood of events must not degrade payments. This is a
   real requirement and it is the one genuine argument for separation.
3. **Cost of being wrong.** This is a system built by one person to be
   understood and defended end to end, not one staffed by three teams.

## Decision

Build a **modular monolith**: one deployable, one database, with hard module
boundaries inside it.

- `app/Domain/<Module>/` per bounded context — `Identity`, `Catalog`, `Billing`,
  and later `Events`. Infrastructure that belongs to no domain lives in
  `app/Support/`.
- Modules talk to each other through explicit application services and domain
  events, never by reaching into another module's Eloquent models.
- Isolation is bought at the **runtime** layer instead of the deployment layer:
  separate Redis instances per workload (ADR-002), separate queue connections
  and Horizon supervisors per workload (ADR-007). That is what actually delivers
  force #2.

## Consequences

**What this buys**

- The outbox pattern becomes a plain local transaction: the state change and the
  `outbox_messages` row commit together, with no distributed transaction and no
  two-phase commit. Across services this is the single hardest thing to get
  right, and getting it wrong is exactly how ledgers stop balancing.
- Idempotency has one home. `payment_intents`, `ledger_entries` and
  `idempotency_records` can carry real database unique constraints, so
  duplicate suppression is enforced by the database rather than by a race
  between two service instances.
- Entitlements are one function over one database, not a network call with a
  cache in front of it and a staleness policy behind it.
- One deploy, one migration ordering, one place to reproduce a bug.

**What this costs, honestly**

- One process means one memory limit and one PHP version. A runaway event
  consumer can starve the web tier of CPU on the same host. Runtime isolation
  mitigates this; it does not eliminate it.
- Scaling is uniform: scaling the event pipeline scales the payment code with
  it, whether or not it needs it. At this load target that is affordable.
- Module boundaries are conventions enforced by tests, not by the network.
  Without enforcement they erode. Phase 7 adds architecture tests that fail the
  build when one module imports another's internals — the boundary has to be
  mechanical or it is decorative.

## Alternatives considered

**Services from day one.** Rejected. It buys deployment isolation we can get
more cheaply at runtime, and it charges for it in the one currency this project
cannot spend: correctness guarantees around money. A cross-service billing flow
needs sagas, compensating transactions and distributed idempotency before it
processes its first payment.

**Monolith with no internal structure.** Rejected. It works until the event
pipeline and the billing code start sharing models, and then the modular split
that Phase 6 depends on is no longer available without a rewrite.

## When to revisit

This decision is wrong the moment any of these becomes true:

- The event pipeline needs to scale independently of billing by more than
  roughly 5×, i.e. runtime isolation stops being enough.
- More than one team owns the codebase and deploy cadence becomes contended.
- A compliance boundary (PCI scope, data residency) requires payment handling to
  be physically separate.

The migration path is deliberately kept open: extract `app/Domain/Events` first,
because it already communicates through Redis Streams and the outbox rather than
through shared models, so it is the one module whose boundary is already a wire
protocol.
