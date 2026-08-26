# ADR-004: Idempotency at every layer money crosses

- **Status:** Accepted
- **Date:** 2026-08-26
- **Phase:** 3

## Context

"Never double-charge anyone" is the brief's hardest promise, because every
boundary a payment crosses can deliver the same message twice or lose the
answer:

- clients retry `POST /v1/payments` after a network blip;
- our charge call to the PSP can time out **after** the money moved —
  ambiguity, not failure;
- the PSP's webhooks arrive late, duplicated, and out of order (our mock is
  built to guarantee it).

One global "idempotency layer" cannot cover these — each boundary has
different actors, different keys, and different storage that can enforce
uniqueness. So idempotency is designed per layer, and the layers are
deliberately redundant: any single check can be bypassed by a bug and the
ledger still cannot double-book.

## Decision

Three layers, each with a clearly-owned key:

| Layer | Key | Owner | Mechanism |
|---|---|---|---|
| 1. Inbound HTTP | `Idempotency-Key` header | client | `idempotency_records`: atomic INSERT claim on a unique key; stored-response replay; request-hash reuse detection; 409 while running; stale-claim takeover after the lock window |
| 2. PSP boundary | `payment_intents.psp_idempotency_key` | us | one ULID minted at intent creation, reused by **every** ChargeJob retry; the PSP collapses the attempt series to at most one charge |
| 3. Application/ledger | charge id → `ledger_entries.idempotency_key` (`charge:{id}:{account}`) | derived from the business fact | state-machine-guarded transitions under a row lock, with database unique constraints as the floor |

Layer-3 detail, because it is where duplicated/reordered webhooks die: every
outcome — synchronous API response and webhook alike — is normalized into
one `PspEvent` shape and applied by one funnel (`ApplyChargeOutcome`) inside
a transaction holding the intent row lock. The decision table is: duplicate
of the applied outcome → no-op; conflict with a terminal state → terminal
wins, logged loudly for reconciliation; otherwise apply atomically
(intent transition + balanced ledger lines + subscription activation).

**Ambiguity policy (layer 2):** a PSP timeout is treated as *unknown*, never
as failure. The intent stays `processing`; the queue retries with the same
key; the webhook can settle it first; and if every attempt exhausts, it
remains `processing` for Phase 4's reconciliation. Guessing "failed" and
retrying with a fresh key is the canonical double-charge bug, and the mock
PSP's timeout-but-charged outcome exists to catch anyone reintroducing it.

## Consequences

- Correctness is layered, so the chaos test can delete any one guard
  mentally and still find the money exact: state guards make duplicates
  cheap no-ops; unique ledger keys make even a guard bypass unable to
  double-book.
- Replayed inbound responses require storing response bodies (bounded, with
  oversized bodies excluded from replay and 5xx never stored, so failures
  stay retryable).
- Keys must have documented lifetimes: inbound records prune after
  `billing.idempotency.retention_hours`; a key reused later is a new
  request. PSP keys live as long as the intent. Ledger keys live forever —
  they are the books.
- Divergence from the roadmap sketch, recorded honestly: uniqueness is on
  `key` alone, not `(key, user_id)` — there is no authenticated per-user
  actor until Module 8's auth work. The column is in place; the scope
  tightens when a real actor exists.

## Alternatives considered

**One idempotency table for everything.** Rejected: the PSP cannot check
our table before charging, and the ledger cannot be protected by rows that
prune after 48 hours. The layers have different lifetimes and enforcement
points; merging them just hides which guarantee lives where.

**Distributed locks instead of unique constraints.** Rejected: a lock with
a TTL is a race with a timer. Every claim here is an atomic INSERT/UPDATE
the database serializes, which cannot expire at the wrong moment.

## When to revisit

- Real per-product/per-user auth lands (Module 8): scope layer-1 keys to
  the authenticated actor.
- A second real PSP: layer 2's "one key per intent" holds, but key format
  and retry windows become per-provider config.
- If reconciliation (Phase 4) ever finds a layer-3 conflict that is not a
  mock-PSP bug, the "terminal wins" policy needs a documented manual
  adjustment path (an `adjustment` ledger transaction, never an edit).
