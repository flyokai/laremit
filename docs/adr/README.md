# Architecture Decision Records

One file per decision, numbered, never edited after acceptance — superseded by a
new ADR instead, with a link both ways. A decision whose record gets quietly
rewritten is a decision nobody can be held to.

| # | Decision | Status | Phase |
|---|---|---|---|
| [0001](0001-modular-monolith-over-services.md) | Modular monolith, not services | Accepted | 1 |
| [0002](0002-redis-topology-and-eviction-policy.md) | Redis topology and eviction policy per instance | Accepted | 1 |
| [0003](0003-redis-streams-over-kafka.md) | Redis Streams as the event buffer, with a named Kafka trigger | Accepted | 2 |
| [0004](0004-idempotency-across-three-layers.md) | Idempotency at every layer money crosses | Accepted | 3 |
| [0005](0005-store-as-source-of-truth-for-iap.md) | The app store is the source of truth for IAP; we hold a projection | Accepted | 4 |
| [0006](0006-thin-plus-event-payloads-through-the-outbox.md) | Thin-plus domain events, through a transactional outbox onto the shared stream | Accepted | 5 |
| [0007](0007-queue-topology-and-isolation.md) | Three queue lanes on one Redis — isolation by supervisor, connection and retry_after | Accepted | 6 |
| 0008 | Octane adoption, with the audit checklist | Planned | 8 |

## Template

```markdown
# ADR-000N: <decision, as a statement not a topic>

- **Status:** Proposed | Accepted | Superseded by ADR-000M
- **Date:** YYYY-MM-DD
- **Phase:** N

## Context
The forces in tension. What makes this a decision rather than a default.

## Decision
What was chosen, concretely enough to check the code against.

## Consequences
What this buys, and what it costs — the cost section is the one that matters.

## Alternatives considered
What was rejected and why. "We didn't think of it" is also an honest answer.

## When to revisit
The observable condition that makes this decision wrong.
```

The last section is the one that separates a record from a rationalisation: if
you cannot name what would change your mind, you have not made a decision.
