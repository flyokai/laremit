# Technical debt register

Deliberate shortcuts, recorded when taken rather than discovered later. Each
entry names the trade-off and what would trigger paying it off.

| # | Debt | Taken in | Why | Trigger to pay it off |
|---|---|---|---|---|
| 1 | PHPStan does not analyse `tests/Feature` and `tests/Unit` | Phase 1 | Pest binds `$this` in test closures at runtime; PHPStan sees `Pest\PendingCalls\TestCall` and flags every `$this->getJson()`. The fix, `pestphp/pest-plugin-phpstan`, needs Pest 5 → PHPUnit 13, and Laravel 13 pins PHPUnit 12. | Laravel 13 supports PHPUnit 13, or Pest backports the plugin. |
| 2 | Tests run against SQLite in-memory, production is MySQL 8.4 | Phase 1 | Keeps the suite at sub-second wall clock and hermetic on a laptop. | Phase 7 CI adds a MySQL job for the nightly run; anything touching partitions, `SKIP LOCKED` or `READ COMMITTED` semantics must be tested there, not on SQLite. |
| 3 | No production image target — the Dockerfile is dev-only (bind mount, `opcache.validate_timestamps=1`, source not baked in) | Phase 1 | Nothing deploys yet. | Phase 9, with the zero-downtime deploy pipeline. |
| 4 | Horizon dashboard has no authorization gate beyond the default `web` middleware | Phase 1 | Local only, not exposed. | Before anything is deployed anywhere reachable. `HorizonServiceProvider::gate()` is already scaffolded and empty. |
| 5 | `subscriptions` allows more than one row per (user, product) | Phase 1 | MySQL has no partial unique index, and "one *active* subscription per product" is a state-machine invariant, not a column constraint. | Phase 3, enforced in the subscription state machine plus a test that proves resubscribe-after-cancel still works. |
