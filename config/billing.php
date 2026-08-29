<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Billing API authentication
    |--------------------------------------------------------------------------
    |
    | Same deliberate minimalism as the ingest token (tech-debt #7): one
    | static bearer token, fail closed when unconfigured.
    |
    */

    'api_token' => env('BILLING_API_TOKEN'),

    'default_currency' => 'USD',

    /*
    |--------------------------------------------------------------------------
    | PSP client
    |--------------------------------------------------------------------------
    |
    | driver http: real HTTP to the mock PSP — real timeouts, used on the
    | live stack (jobs run in the horizon container, so the base_url uses the
    | compose service name). driver loopback: the same mock PSP logic invoked
    | in-process — deterministic and clock-free, used by the test suite.
    |
    | timeout_seconds is deliberately shorter than the mock PSP's timeout
    | sleep: a "timeout" outcome must actually look like one to the client.
    |
    */

    'psp' => [
        'driver' => env('BILLING_PSP_DRIVER', 'http'),
        'base_url' => env('BILLING_PSP_BASE_URL', 'http://localhost:8100/mock-psp'),
        'timeout_seconds' => (float) env('BILLING_PSP_TIMEOUT', 3.0),
    ],

    /*
    |--------------------------------------------------------------------------
    | Inbound webhooks
    |--------------------------------------------------------------------------
    |
    | webhook_secret: the shared HMAC secret behind X-Psp-Signature
    | (t=<ts>,v1=<hmac of "<ts>.<body>">). tolerance_seconds bounds replay of
    | a captured delivery. pending_after_minutes is the reaper threshold: a
    | persisted delivery still `pending` that long was never dispatched (or
    | its job died) and is re-queued by reconciliation. retention_days bounds
    | the raw-payload archive; pending rows are never pruned.
    |
    */

    'webhook_secret' => env('PSP_WEBHOOK_SECRET'),

    'webhooks' => [
        'tolerance_seconds' => (int) env('BILLING_WEBHOOK_TOLERANCE', 300),
        'pending_after_minutes' => (int) env('BILLING_WEBHOOK_PENDING_AFTER', 5),
        'retention_days' => (int) env('BILLING_WEBHOOK_RETENTION_DAYS', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | App stores (Apple / Google in-app purchases) — ADR-005
    |--------------------------------------------------------------------------
    |
    | The stores own IAP subscription state; we hold a projection. driver and
    | base_url select how the projection is refreshed (http against the mock
    | stores on the live stack, loopback in-process for tests).
    |
    | environment: notifications for any other environment are discarded.
    | A Sandbox purchase granting Production access is the classic IAP bug;
    | this is the one line that prevents it.
    |
    | apple.public_key: base64 of the PEM public key ASSN v2 payloads verify
    | against. In production this is Apple's root CA and an x5c chain walk
    | (tech-debt #12); here it pins the mock store's signing key.
    | google.pubsub_token: the verification token the Pub/Sub push
    | subscription appends to the endpoint URL (?token=). Fail closed.
    |
    */

    'stores' => [
        'driver' => env('BILLING_STORES_DRIVER', 'http'),
        'base_url' => env('BILLING_STORES_BASE_URL', 'http://localhost:8100/mock-stores'),
        'timeout_seconds' => (float) env('BILLING_STORES_TIMEOUT', 3.0),
        'environment' => env('BILLING_STORES_ENVIRONMENT', 'Sandbox'),
        'product_id_prefix' => env('BILLING_STORES_PRODUCT_PREFIX', 'com.laremit.'),
        'apple' => [
            'bundle_id' => env('APPLE_BUNDLE_ID', 'com.laremit.app'),
            'public_key' => env('APPLE_ASSN_PUBLIC_KEY'),
        ],
        'google' => [
            'package_name' => env('GOOGLE_PACKAGE_NAME', 'com.laremit.app'),
            'pubsub_token' => env('GOOGLE_RTDN_TOKEN'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Reconciliation
    |--------------------------------------------------------------------------
    |
    | window_hours: how far back the provider's charge list is pulled on
    | each hourly run — overlapping windows, never tiled, so a run that is
    | late or skipped cannot leave a gap. stuck_after_minutes: derived from
    | the ChargeJob's worst case (queue wait + 5 attempts with backoff
    | 2+5+15+30s + PSP timeouts) with headroom, so a healthy in-flight
    | charge is never mistaken for a stuck one. max_recovery_attempts bounds
    | how often a charge the PSP has never heard of is re-dispatched before
    | it is escalated instead.
    |
    */

    'reconciliation' => [
        'window_hours' => (int) env('BILLING_RECONCILE_WINDOW_HOURS', 26),
        'stuck_after_minutes' => (int) env('BILLING_RECONCILE_STUCK_AFTER', 15),
        'max_recovery_attempts' => (int) env('BILLING_RECONCILE_MAX_RECOVERY', 3),
    ],

    /*
    |--------------------------------------------------------------------------
    | Inbound idempotency (ADR-004, layer 1)
    |--------------------------------------------------------------------------
    |
    | lock_seconds: how long an in-progress record blocks concurrent
    | duplicates before a crashed original may be taken over. Must exceed the
    | slowest honest request. retention_hours bounds replay: after that a
    | reused key is treated as new, so clients must not retry older than this.
    |
    */

    'idempotency' => [
        'lock_seconds' => (int) env('BILLING_IDEMPOTENCY_LOCK', 30),
        'retention_hours' => (int) env('BILLING_IDEMPOTENCY_RETENTION', 48),
        'max_key_length' => 128,
        'max_body_bytes' => 65_536,
    ],

];
