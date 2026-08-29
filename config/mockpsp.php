<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Mock PSP
    |--------------------------------------------------------------------------
    |
    | A deliberately adversarial stand-in payment provider. It honours
    | idempotency keys exactly (same key + same request => same stored
    | response; same key + different request => 409), and it ships every
    | nasty behaviour the chaos test needs:
    |
    |   - deterministic outcomes by amount convention (Stripe-style):
    |       amount_minor % 100 == 1  -> timeout, but the charge SUCCEEDS
    |       amount_minor % 100 == 2  -> declined
    |       amount_minor % 100 == 3  -> timeout, nothing recorded
    |     anything else follows the random rates below; metadata.force
    |     ("succeed" | "declined" | "timeout_charged" | "timeout_lost")
    |     overrides everything.
    |   - webhooks fired late (random delay), duplicated (duplicate_rate),
    |     dropped outright (drop_rate), and therefore out of order across
    |     charges.
    |
    | The %100==1 case is the whole reason this mock exists: the caller sees
    | a timeout, the money moved anyway, and only an idempotent retry or the
    | webhook can tell the truth.
    |
    */

    'enabled' => (bool) env('MOCKPSP_ENABLED', true),

    'outcomes' => [
        'declined_rate' => (float) env('MOCKPSP_DECLINED_RATE', 0.0),
        'timeout_rate' => (float) env('MOCKPSP_TIMEOUT_RATE', 0.0),
    ],

    'timeout' => [
        // Longer than billing.psp.timeout_seconds, so an HTTP caller
        // genuinely gives up before the response exists.
        'sleep_seconds' => (int) env('MOCKPSP_TIMEOUT_SLEEP', 6),
    ],

    'webhook' => [
        'url' => env('MOCKPSP_WEBHOOK_URL', 'http://localhost:8100/v1/psp/webhook'),
        // Shared with billing.webhook_secret — one secret, two sides.
        'secret' => env('PSP_WEBHOOK_SECRET'),
        'delay_seconds' => [
            (int) env('MOCKPSP_WEBHOOK_DELAY_MIN', 0),
            (int) env('MOCKPSP_WEBHOOK_DELAY_MAX', 3),
        ],
        'duplicate_rate' => (float) env('MOCKPSP_WEBHOOK_DUPLICATE_RATE', 0.25),
        // Phase 4's lever: a dropped webhook is never sent at all, and only
        // reconciliation can find what it would have said.
        'drop_rate' => (float) env('MOCKPSP_WEBHOOK_DROP_RATE', 0.0),
    ],

];
