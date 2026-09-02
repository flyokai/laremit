# CI — the fast/nightly split

The design rule (Module 9): the push pipeline must be fast and boringly
green, so nobody learns to ignore it; everything slow or deliberately
non-deterministic runs nightly, where red means "a bug was found overnight",
not "retry the job". A pipeline that is red 30% of the time for
environmental reasons is worse than no pipeline.

## What runs where

| | `ci.yml` (push / PR) | `nightly.yml` (02:30 UTC + manual) |
|---|---|---|
| Lint (`pint --test`) | ✔ | — |
| Larastan level 7, no baseline | ✔ | — |
| Full Pest suite (sqlite + real Redis ×3) | ✔ | — |
| Concurrency group (real MySQL + served app) | ✔ (part of the suite) | ✔ |
| Chaos group | ✔ deterministic seeds | ✔ ×5 run-derived seeds |
| Full suite with **MySQL as default connection** | — | ✔ |
| `composer audit` | ✔ | ✔ (catches new advisories without a push) |

Local wall-clock on the dev machine (325 tests, 1 652 assertions):

| Step | Time |
|---|---|
| `pint --test` | 0.3 s |
| `phpstan` (cold cache) | 4.8 s |
| Full suite, sqlite | ~5 s |
| Full suite, MySQL | ~7.5 s |
| Chaos group alone | 0.8 s |

The 5-minute budget for the whole pipeline is dominated by runner spin-up
and `composer install`, not by the suite. Numbers above are the honest local
measurement; CI adds cache-restore and service-boot overhead on top.

## Suite topology

- `tests/Unit` — pure logic, no database.
- `tests/Feature` — HTTP boundary + jobs, sqlite in-memory via
  `RefreshDatabase`; the event-pipeline and flood tests use the real Redis
  containers (database 9, test prefix) and skip when the stack is down.
- `tests/Arch` — executable architecture: module boundaries, one-writer
  ledger, jobs queueable, strict types, security preset. A convention in a
  review checklist decays; a convention that fails the build doesn't.
- `--group=chaos` — the deliverable proofs: exact ledger through
  duplicated/reordered/dropped webhooks, reconciliation convergence,
  consumer and relay kill-recovery.
- `--group=concurrency` — 20 genuinely parallel HTTP requests (Guzzle async
  against `php -S` with a 25-worker pool, throwaway MySQL database) →
  exactly one charge. Asserts invariants, not interleavings. Skips cleanly
  when MySQL is unreachable, same posture as the Redis tests.
- `tests/Feature/Octane` — the interleaved-user leak test (ADR-008): the
  real Octane `Worker` in a child process (sqlite, no external services, so
  it rides the fast tier), asserting both that `scoped()` never leaks a
  user across requests and that the planted warm-singleton leak IS caught.

`Http::preventStrayRequests()` is global: no test can silently talk to the
network. The concurrency suite talks to a server it booted itself, over raw
Guzzle, deliberately outside the facade.

## Chaos seeds

Chaos tests seed `mt_srand` through `chaosSeed(default)`. Unset, the fixed
default makes push CI reproducible byte for byte. Nightly exports
`CHAOS_SEED` derived from the run id, five values per night — the invariants
must hold for *every* ordering, so a red roulette is a real bug, and the log
prints the seed to `export CHAOS_SEED=… ` locally and replay it.

## Why the MySQL nightly exists — day-one catches

The sqlite suite is the speed tier, not the truth tier (tech-debt #2). The
first time the whole suite ran on MySQL 8.4 it found, in one run:

1. `mock_store_subscriptions.status VARCHAR(32)` — strict mode rejects
   Google's own `SUBSCRIPTION_STATE_IN_GRACE_PERIOD` (34 chars). sqlite
   ignores column lengths.
2. `psp_charges.response_body JSON` — MySQL's JSON type normalizes key
   order, breaking the mock PSP's byte-for-byte idempotent-replay contract.
3. `mock_store_subscriptions.period_end DATETIME` (whole seconds) beside a
   millisecond `event_at`: MySQL *rounds* ≥.500 up to the next second, so
   ~half of all grace/expiry notifications signed a period end *after* the
   event that ended it and mapped to Active — a 60%-flaky failure sqlite
   could never produce.

All three are fixed in `2026_09_01_000001_fix_mock_columns_found_by_mysql_suite`.

## What the concurrency test found

The 20-distinct-keys variant (idempotency layer deliberately bypassed)
produced **one subscription with five settled charges** on its first run:
`CreatePaymentIntent`'s `FOR UPDATE` matched no row on a first purchase, so
rivals had nothing to wait behind, and an `incomplete` subscription with an
in-flight intent was not treated as blocking. Fixed twice over: the user row
is now the serialization anchor (it always exists), and a pending/processing
intent on the subscription refuses a second purchase with
`409 payment_in_progress` naming the intent to poll.
