<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Mock App Store / Play Store
    |--------------------------------------------------------------------------
    |
    | Pretend stores that own IAP subscription state the way the real ones
    | do (ADR-005). They speak the real wire shapes — App Store Server
    | Notifications V2 as ES256-signed JWS, Real-Time Developer Notifications
    | as Pub/Sub push envelopes, and the store APIs the app re-fetches truth
    | from — and they are hostile in the same three ways the mock PSP is:
    | notifications delivered late, duplicated, and (drop_rate) sometimes
    | not at all. The last one is the Phase 4 deliverable's lever.
    |
    | apple.signing_key: base64 of the PEM ES256 private key the mock signs
    | with. Its public half is billing.stores.apple.public_key. A committed
    | dev key pair, on purpose: nothing real ever signs with it.
    |
    */

    'enabled' => (bool) env('MOCKSTORES_ENABLED', true),

    'environment' => env('MOCKSTORES_ENVIRONMENT', 'Sandbox'),

    'apple' => [
        'signing_key' => env('MOCK_APPLE_SIGNING_KEY'),
        'bundle_id' => env('APPLE_BUNDLE_ID', 'com.laremit.app'),
        'notification_url' => env('MOCKSTORES_APPLE_NOTIFICATION_URL', 'http://localhost:8100/v1/iap/apple/notifications'),
    ],

    'google' => [
        'package_name' => env('GOOGLE_PACKAGE_NAME', 'com.laremit.app'),
        'notification_url' => env('MOCKSTORES_GOOGLE_NOTIFICATION_URL', 'http://localhost:8100/v1/iap/google/notifications'),
        // Shared with billing.stores.google.pubsub_token — one token, two sides.
        'pubsub_token' => env('GOOGLE_RTDN_TOKEN'),
    ],

    'delivery' => [
        'delay_seconds' => [
            (int) env('MOCKSTORES_DELAY_MIN', 0),
            (int) env('MOCKSTORES_DELAY_MAX', 3),
        ],
        'duplicate_rate' => (float) env('MOCKSTORES_DUPLICATE_RATE', 0.25),
        'drop_rate' => (float) env('MOCKSTORES_DROP_RATE', 0.0),
    ],

];
