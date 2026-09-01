<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Redis;

/**
 * Integration test against the real queue Redis instance (database 9, test
 * prefix — see phpunit.xml), same posture as the event pipeline suite:
 * skips when the compose stack is down, refuses to flush anything that is
 * not the test database.
 */
beforeEach(function (): void {
    if ((int) config('database.redis.queue.database') !== 9) {
        $this->fail('Redis [queue] is not on test database 9; refusing to flush.');
    }

    try {
        Redis::connection('queue')->ping();
    } catch (Throwable) {
        $this->markTestSkipped('Queue Redis instance is not reachable; is the compose stack up?');
    }

    Redis::connection('queue')->flushdb();

    // The suite runs the lanes on sync (phpunit.xml); the flood pushes real
    // payloads, so this test alone puts the events lane back on Redis.
    config()->set('queue.connections.events.driver', 'redis');
});

afterEach(function (): void {
    if ((int) config('database.redis.queue.database') === 9) {
        try {
            Redis::connection('queue')->flushdb();
        } catch (Throwable) {
            // unreachable — the test skipped anyway
        }
    }
});

it('parks N runnable payloads on the events lane, each with a unique id', function (): void {
    $this->artisan('queue:flood', ['count' => 25, '--chunk' => 10])
        ->expectsOutputToContain('depth is now 25')
        ->assertSuccessful();

    $conn = Redis::connection('queue');

    expect((int) $conn->command('llen', ['queues:events']))->toBe(25)
        // ...and the template scratch queue holds nothing a worker could eat.
        ->and((int) $conn->command('llen', ['queues:flood-template']))->toBe(0);

    /** @var list<string> $raw */
    $raw = $conn->command('lrange', ['queues:events', 0, -1]);
    $ids = [];

    foreach ($raw as $json) {
        /** @var array<string, mixed> $payload */
        $payload = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        // A payload a real worker would run: framework entry point, our job.
        expect($payload['job'])->toBe('Illuminate\Queue\CallQueuedHandler@call')
            ->and($payload['displayName'])->toBe(App\Support\Queue\Jobs\SyntheticEventJob::class)
            ->and($payload['attempts'])->toBe(0);

        $ids[] = (string) $payload['uuid'];
    }

    expect(array_unique($ids))->toHaveCount(25);
});

it('refuses lanes that are not redis-backed', function (): void {
    $this->artisan('queue:flood', ['count' => 5, '--lane' => 'payments'])
        ->expectsOutputToContain('not a redis queue connection')
        ->assertFailed();
});
