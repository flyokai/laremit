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
    | PSP webhooks (inbound)
    |--------------------------------------------------------------------------
    |
    | Shared HMAC secret for X-Psp-Signature. Phase 3 verifies the signature
    | and applies idempotently; Phase 4 adds timestamp tolerance, raw
    | persistence and provider-event-id uniqueness (tech-debt #10).
    |
    */

    'webhook_secret' => env('PSP_WEBHOOK_SECRET'),

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
