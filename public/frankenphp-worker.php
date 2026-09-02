<?php

declare(strict_types=1);

// FrankenPHP worker-mode entrypoint (Phase 8, ADR-008). The server keeps this
// script resident: Octane boots the framework once, then serves requests from
// the booted application. Enabled by FRANKENPHP_CONFIG in compose.yaml —
// unset that variable and the same container serves classic mode through
// public/index.php, which is what the before/after rows in
// docs/load-tests.md are measured against.
//
// MAX_REQUESTS (compose.yaml) is the recycle ceiling the vendor loop below
// honours: after that many requests the script returns and FrankenPHP starts
// a fresh worker, which turns any slow leak into a bounded one.

$_SERVER['APP_BASE_PATH'] = dirname(__DIR__);

require __DIR__.'/../vendor/laravel/octane/bin/frankenphp-worker.php';
