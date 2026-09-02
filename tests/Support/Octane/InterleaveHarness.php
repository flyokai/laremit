<?php

declare(strict_types=1);

namespace Tests\Support\Octane;

use App\Domain\Billing\Models\Subscription;
use App\Domain\Catalog\Models\Plan;
use App\Domain\Catalog\Models\Product;
use App\Domain\Identity\Models\User;
use Illuminate\Http\Request;
use Laravel\Octane\ApplicationFactory;
use Laravel\Octane\RequestContext;
use Laravel\Octane\Testing\Fakes\FakeClient;
use Laravel\Octane\Worker;

/**
 * The interleaved-user rig (Module 10, ADR-008): boot the REAL Octane worker
 * — the same Worker class public/frankenphp-worker.php runs, with its real
 * sandbox-per-request lifecycle and warm list — and feed it two users'
 * entitlement requests alternately. A subscribed user and an unsubscribed
 * one, interleaved, is the traffic shape that turns a cross-request state
 * leak into a visible wrong answer instead of a silent coincidence: with
 * uniform traffic every leaked answer happens to be the right one.
 *
 * Runs in a dedicated child process (interleave-runner.php) because a Worker
 * owns process-global state — Container::setInstance, facade roots — that
 * must not be fought over with the phpunit application. The child prints one
 * JSON document on stdout; the test in tests/Feature/Octane asserts on it,
 * twice: the scoped() binding stays correct, and the planted
 * warm-plus-singleton binding is CAUGHT, which is what proves the test could
 * ever catch anything.
 */
final class InterleaveHarness
{
    private const ROUNDS = 5;

    private const TOKEN = 'octane-interleave-token';

    /**
     * @return array{leak_demo: bool, exchanges: list<array{requested: int, returned: int|null, has_access: bool|null, status: int}>}
     */
    public static function run(bool $leakDemo): array
    {
        self::environment($leakDemo);

        $worker = new Worker(
            new ApplicationFactory(dirname(__DIR__, 3)),
            $client = new FakeClient([]),
        );
        $worker->boot();

        $app = $worker->application();

        // sqlite :memory: lives and dies with this process's one PDO, so the
        // schema must be built in-process, through the same connection the
        // worker's requests will use.
        $app->make('migration.repository')->createRepository();
        $app->make('migrator')->run($app->databasePath('migrations'));

        [$subscribed, $unsubscribed] = self::seed();

        $requests = [];
        for ($round = 0; $round < self::ROUNDS; $round++) {
            $requests[] = $subscribed;
            $requests[] = $unsubscribed;
        }

        foreach ($requests as $user) {
            $request = Request::create(
                '/v1/entitlements?user_id='.$user->id.'&product=vpn',
                'GET',
                server: [
                    'HTTP_AUTHORIZATION' => 'Bearer '.self::TOKEN,
                    'HTTP_ACCEPT' => 'application/json',
                ],
            );

            $worker->handle($request, new RequestContext(['request' => $request]));
        }

        $exchanges = [];

        foreach ($client->responses as $index => $response) {
            /** @var array{user_id?: int, has_access?: bool} $body */
            $body = (array) json_decode((string) $response->getContent(), true);

            $exchanges[] = [
                'requested' => $requests[$index]->id,
                'returned' => $body['user_id'] ?? null,
                'has_access' => $body['has_access'] ?? null,
                'status' => $response->getStatusCode(),
            ];
        }

        $worker->terminate();

        return ['leak_demo' => $leakDemo, 'exchanges' => $exchanges];
    }

    /**
     * Hermetic overrides, applied before the app boots. Dotenv is immutable,
     * so anything set here wins over .env — the same precedence the compose
     * environment enjoys in the real container.
     */
    private static function environment(bool $leakDemo): void
    {
        $overrides = [
            'APP_ENV' => 'testing',
            // The real worker runs with this false (octane's bin/bootstrap.php
            // sets it); providers must see the same shape here.
            'APP_RUNNING_IN_CONSOLE' => false,
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => ':memory:',
            'CACHE_STORE' => 'array',
            'SESSION_DRIVER' => 'array',
            'QUEUE_CONNECTION' => 'sync',
            'QUEUE_LANES_DRIVER' => 'sync',
            'LOG_CHANNEL' => 'stderr',
            'BILLING_API_TOKEN' => self::TOKEN,
            'BILLING_PSP_DRIVER' => 'loopback',
            'BILLING_STORES_DRIVER' => 'loopback',
            'OCTANE_DEMO_CROSS_REQUEST_LEAK' => $leakDemo ? 'true' : 'false',
        ];

        // Both superglobals: when phpunit spawns this runner, its <env>
        // values arrive as REAL process environment and land in $_SERVER,
        // which Laravel's env repository consults ahead of $_ENV — an
        // override written to $_ENV alone loses to the inherited value.
        foreach ($overrides as $name => $value) {
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
    }

    /**
     * @return array{0: User, 1: User}
     */
    private static function seed(): array
    {
        $product = Product::factory()->create(['slug' => 'vpn']);
        $plan = Plan::factory()->create(['product_id' => $product->id]);

        $subscribed = User::factory()->create();
        $unsubscribed = User::factory()->create();

        Subscription::factory()->create([
            'user_id' => $subscribed->id,
            'plan_id' => $plan->id,
            'product_id' => $product->id,
        ]);

        return [$subscribed, $unsubscribed];
    }
}
