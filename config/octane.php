<?php

declare(strict_types=1);

use App\Http\Context\ActingUser;
use Laravel\Octane\Contracts\OperationTerminated;
use Laravel\Octane\Events\RequestHandled;
use Laravel\Octane\Events\RequestReceived;
use Laravel\Octane\Events\RequestTerminated;
use Laravel\Octane\Events\TaskReceived;
use Laravel\Octane\Events\TaskTerminated;
use Laravel\Octane\Events\TickReceived;
use Laravel\Octane\Events\TickTerminated;
use Laravel\Octane\Events\WorkerErrorOccurred;
use Laravel\Octane\Events\WorkerStarting;
use Laravel\Octane\Events\WorkerStopping;
use Laravel\Octane\Listeners\CloseMonologHandlers;
use Laravel\Octane\Listeners\EnsureUploadedFilesAreValid;
use Laravel\Octane\Listeners\EnsureUploadedFilesCanBeMoved;
use Laravel\Octane\Listeners\FlushOnce;
use Laravel\Octane\Listeners\FlushTemporaryContainerInstances;
use Laravel\Octane\Listeners\ReportException;
use Laravel\Octane\Listeners\StopWorkerIfNecessary;
use Laravel\Octane\Octane;

/*
 * Octane wiring (Phase 8, ADR-008). The app runs FrankenPHP in native worker
 * mode: FRANKENPHP_CONFIG in compose.yaml points the server at
 * public/frankenphp-worker.php, which boots an Octane Worker — there is no
 * `octane:start` process in the container, the web server IS the worker
 * supervisor. Swoole-only sections of the stock config (tables, cache, watch)
 * are omitted for that reason.
 */
return [

    'server' => env('OCTANE_SERVER', 'frankenphp'),

    // TLS is terminated upstream (see docker/app/Caddyfile); generated links
    // stay scheme-relative to what the edge saw.
    'https' => env('OCTANE_HTTPS', false),

    /*
     * The state-reset pipeline. These listeners are the real documentation of
     * what leaks between requests in a long-lived worker — reading them is
     * step one of the ADR-008 audit. The stock set is deliberately kept
     * as-is: everything this app needs reset (scoped instances, once()
     * memoization, request-tainted framework services) is already covered.
     */
    'listeners' => [
        WorkerStarting::class => [
            EnsureUploadedFilesAreValid::class,
            EnsureUploadedFilesCanBeMoved::class,
        ],

        RequestReceived::class => [
            ...Octane::prepareApplicationForNextOperation(),
            ...Octane::prepareApplicationForNextRequest(),
        ],

        RequestHandled::class => [
            //
        ],

        RequestTerminated::class => [
            //
        ],

        TaskReceived::class => [
            ...Octane::prepareApplicationForNextOperation(),
        ],

        TaskTerminated::class => [
            //
        ],

        TickReceived::class => [
            ...Octane::prepareApplicationForNextOperation(),
        ],

        TickTerminated::class => [
            //
        ],

        OperationTerminated::class => [
            FlushOnce::class,
            FlushTemporaryContainerInstances::class,
        ],

        WorkerErrorOccurred::class => [
            ReportException::class,
            StopWorkerIfNecessary::class,
        ],

        WorkerStopping::class => [
            CloseMonologHandlers::class,
        ],
    ],

    /*
     * Services resolved once at worker boot and shared, as the same object,
     * by every request the worker serves. That sharing is the whole Octane
     * speedup — and the whole hazard: anything warmed must be stateless or
     * have a reset listener above. The framework defaults all do; the demo
     * splice below is the deliberate counter-example.
     */
    'warm' => [
        ...Octane::defaultServicesToWarm(),

        // THE PLANTED LEAK (ADR-008, off by default). Warming the acting-user
        // context turns its lazily-safe singleton binding (see
        // AppServiceProvider) into a boot-resolved object shared across
        // requests: the first user a worker serves becomes every user it
        // serves. tests/Feature/Octane proves the interleaved-user test
        // catches this, and that the scoped() binding is immune even when
        // warmed. Never set the flag outside that demonstration.
        ...(env('OCTANE_DEMO_CROSS_REQUEST_LEAK', false) ? [ActingUser::class] : []),
    ],

    'flush' => [
        //
    ],

    // Switches AppServiceProvider's ActingUser binding from scoped() to
    // singleton() — the container half of the plant; the warm entry above is
    // the resolution half. Both halves read the same env var so the demo is
    // one flag, not a matched pair someone can half-enable.
    'demo_cross_request_leak' => env('OCTANE_DEMO_CROSS_REQUEST_LEAK', false),

    // Force a gc_collect_cycles() once a worker's usage crosses this many MB;
    // the recycle ceiling itself is MAX_REQUESTS in compose.yaml.
    'garbage' => 50,

    'max_execution_time' => 30,

    'state_file' => env('OCTANE_STATE_FILE', storage_path('logs/octane-server-state.json')),

];
