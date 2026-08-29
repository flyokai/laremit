# ADR-005: The app store is the source of truth for in-app subscriptions; we hold a projection

- **Status:** Accepted
- **Date:** 2026-08-29
- **Phase:** 4

## Context

For card payments (Phase 3) we own the subscription: we decide to charge,
we call the PSP, we settle the outcome. For Apple and Google in-app
purchases the relationship is inverted. The store charges, renews, retries,
refunds and revokes on its own schedule, and tells us afterwards — late,
twice, out of order, or not at all. We cannot charge a store subscriber,
cannot refund them, and cannot stop a family-sharing revocation. Our
database being "the truth" would mean being confidently wrong the first
time Apple refunds someone at 3am.

At the same time, every product answers `hasAccessTo(user, product)` from
*our* database in milliseconds, thousands of times a second. The answer
must be local; the truth is not.

Two more forces shape the design:

1. **Notifications are hints, not state.** Google's RTDN carries a purchase
   token and a type — nothing else. Apple's ASSN v2 carries full signed
   state, but a notification type like `DID_RENEW` is still a delta, and
   applying deltas out of order corrupts.
2. **The device is an adversary.** "I bought it" from a client is worth
   nothing; granting on a client claim is how an app gets pirated in a
   weekend.

## Decision

**A store-backed subscription row is a projection of the store's record,
never an independent fact.** Concretely:

1. **Identity is the store's.** `subscriptions.store` +
   `store_original_transaction_id` name the store's record — Apple's
   `originalTransactionId` (stable across resubscribes) or Google's
   `purchaseToken` (which changes on resubscribe; the new record's
   `linkedPurchaseToken` re-keys the same row). The link to our user is the
   `app_account_token` the app attaches at purchase time and the store echoes
   back *signed* — never anything the device asserts.

2. **One writer, one shape.** Only `StoreSubscriptionProjector` writes a
   store-backed row, and its only input is a `StoreSubscriptionSnapshot`:
   the store's *complete current statement*, already mapped into our status
   vocabulary. Four roads produce it — Apple's verified signed payload,
   Google's RTDN followed by a re-fetch from the Play Developer API, the
   hourly re-sync, and the client's restore call — and the projector cannot
   tell which. Absolute state, never a delta.

3. **Ordering is the store's clock.** Every snapshot carries the store's
   timestamp for that state (Apple `signedDate`, Google `eventTimeMillis`,
   the fetch time for re-syncs). The projector writes under the row lock
   with one atomic guarded UPDATE — `WHERE status = :from AND (last_event_at
   IS NULL OR last_event_at < :event_at)` — so an older snapshot loses,
   whatever order it arrived in, and a duplicate is a no-op. Millisecond
   precision on `last_event_at`, because Apple's is.

4. **The allow-list is advisory for mirrors.** Our transition allow-list
   encodes *our* policy. The store cannot be wrong about a subscription it
   owns, so `SubscriptionStateMachine::mirror()` applies whatever the store
   says and logs a warning when that falls outside the allow-list: the log
   is a bug report against our state model, not a reason to disagree with
   money. This is also why Apple's reuse of one `originalTransactionId`
   across a resubscribe-after-expiry brings the *same row* back to Active.

5. **Never grant from the device.** `POST /v1/iap/{store}/sync` takes a
   store identifier, re-fetches the record from the store, refuses it if the
   store links it to a different account, and projects what the store said.
   It is also the only recovery for a purchase whose every notification was
   dropped: reconciliation cannot see a store record it has no row for.

6. **Environments never mix.** A snapshot whose environment differs from
   `billing.stores.environment` is discarded and kept for forensics. A
   Sandbox subscription granting Production access is the canonical IAP bug.

7. **Revocation is immediate, from any state.** Apple `REFUND`/`REVOKE` (a
   `revocationDate` on the signed transaction) and Google's `SUBSCRIPTION_REVOKED`
   type project to `Revoked`. Google is the one deliberate exception to
   "state, not type": after revocation the Play API merely says `EXPIRED`,
   so the notification type upgrades that to `Revoked`, and a revoked row
   that the store later calls expired is treated as consistent, not drift.

8. **IAP money is not in our ledger.** The ledger records money *we* move
   through the PSP. Apple and Google collect, net their commission, and pay
   out on their own statements; those statements are the books for IAP.
   Booking our own "IAP revenue" lines would be a second set of books that
   can only disagree with the first. Payout reconciliation against the
   stores' financial reports is a later phase (tech-debt #11).

**Status mapping** — the one place store vocabulary is translated:

| Store says | Ours | Why |
|---|---|---|
| Apple: not expired, auto-renew on · Google `ACTIVE` | `active` | |
| Apple: not expired, auto-renew off · Google `CANCELED` | `canceled` | access to period end, by `current_period_end` |
| Apple: expired, in billing retry, grace period running · Google `IN_GRACE_PERIOD` | `past_due` | access retained while the store retries |
| Apple: expired, in billing retry, no grace · Google `ON_HOLD`, `PAUSED` | `paused` | no access, not over, may come back — the store's own word is kept in the log |
| Apple: expired, not retrying · Google `EXPIRED` | `expired` | |
| Apple: `revocationDate` set · Google type `SUBSCRIPTION_REVOKED` | `revoked` | |
| Google `PENDING` | `incomplete` | |

**Reconciliation** for stores is one-directional by nature: hourly, every
live store-backed row is re-fetched and projected with the fetch time as its
clock (which also advances the watermark past any late notification). A row
the store has no record of pages. The other direction — a store record we
have no row for — is only reachable through the client's restore call.

## Consequences

- Drops, duplicates and reordering cost nothing: the funnel and the
  watermark absorb them, and one hourly run converges whatever they left.
- A forged or replayed notification can at most trigger a re-read of the
  truth. Google's push authenticity (a URL token) is deliberately weak
  *because* the body is never believed.
- Local state can lag the store by up to an hour when a notification is
  lost. It can be stale; it cannot be wrong in the dangerous direction for
  longer than that.
- A store API outage stalls re-syncs and Google notification processing
  (the jobs retry, then reconciliation catches up). It never changes local
  state — `StoreUnavailable` is ambiguity, like `PspUnavailable`.
- One Play API call per Google notification and one store call per live
  row per hour. Fine at capstone scale; at volume this needs the batched
  status APIs and a longer re-sync interval for stable rows (tech-debt #14).
- Our vocabulary is lossy on purpose: `ON_HOLD` and user-initiated `PAUSED`
  both become `paused`. For entitlement they are identical; anyone who
  needs the distinction reads the store's word in the webhook record.
- Two production deltas are named rather than faked: Apple's `x5c` chain
  walk to the Apple Root CA (we pin the signing key; tech-debt #12), and
  Pub/Sub's OIDC push authentication (we use the URL token; tech-debt #13).

## Alternatives considered

**Apply notifications as deltas** ("DID_RENEW → extend the period").
Rejected: a delta applied twice or out of order corrupts, and "reject the
older one" is only safe when the newer one carries complete state.

**Our database as the truth, the store as a payment method.** Rejected:
the store acts without asking. Every refund, revocation and billing retry
would be a conflict we lose.

**Always re-fetch, Apple included.** Rejected for now. Apple's signed
payload *is* the store speaking, verified byte for byte; re-fetching adds a
round trip and a dependency on the API being healthy for every notification.
Google gets no choice. The seam (`StoreClient`) makes switching Apple to
re-fetch a one-line change if payload drift ever appears.

**Verify receipts on the device / trust the app.** Rejected: piracy.

**Book IAP revenue in the ledger from notifications.** Rejected: our ledger
would carry amounts we never saw move, in a currency and net of a
commission we do not know at notification time, and it would disagree with
the store's statement by construction.

## When to revisit

- Going to production with real stores: Apple root-CA chain validation
  and Google OIDC push auth must replace the pinned key and the URL token.
- Store API quota pressure from the hourly per-row re-sync: move to
  batched status endpoints and re-sync stable rows less often.
- A reconciliation run that finds `store_drift` every hour: the
  notification path is broken, not the model — fix the path.
- Any entitlement disagreement that reconciliation does not close within
  one run means a snapshot road is producing a wrong shape; that is a bug
  in a parser, never a reason to add a second writer.
- IAP revenue recognition becomes a requirement: add payout reconciliation
  from the stores' financial reports, as its own ledger accounts, in its
  own phase.
