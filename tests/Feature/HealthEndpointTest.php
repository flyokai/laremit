<?php

declare(strict_types=1);

use App\Support\Health\HealthChecker;
use Tests\Support\FakeCheck;

it('answers liveness on /up', function (): void {
    $this->get('/up')->assertOk();
});

it('answers 200 with per-dependency detail when everything is reachable', function (): void {
    $this->app->instance(HealthChecker::class, new HealthChecker([
        new FakeCheck('database', detail: ['driver' => 'mysql']),
        new FakeCheck('redis:cache', detail: ['maxmemory_policy' => 'allkeys-lru']),
        new FakeCheck('redis:queue', detail: ['maxmemory_policy' => 'noeviction']),
        new FakeCheck('redis:stream', detail: ['maxmemory_policy' => 'noeviction']),
    ]));

    $this->getJson('/health')
        ->assertOk()
        ->assertJsonPath('status', 'ok')
        ->assertJsonPath('checks.redis:queue.detail.maxmemory_policy', 'noeviction');
});

it('answers 503 and names the broken dependency', function (): void {
    $this->app->instance(HealthChecker::class, new HealthChecker([
        new FakeCheck('database'),
        new FakeCheck('redis:queue', throws: 'maxmemory-policy is "allkeys-lru", expected "noeviction" (see ADR-002)'),
    ]));

    $this->getJson('/health')
        ->assertStatus(503)
        ->assertJsonPath('status', 'failing')
        ->assertJsonPath('checks.database.status', 'ok')
        ->assertJsonPath('checks.redis:queue.status', 'failing');
});

it('never starts a session', function (): void {
    $this->app->instance(HealthChecker::class, new HealthChecker([new FakeCheck('database')]));

    // A probe hitting this every few seconds through the web middleware group
    // would write a session record per request, on the very Redis instance the
    // probe exists to protect.
    $response = $this->getJson('/health');

    expect($response->headers->getCookies())->toBeEmpty();
});

it('is never cached', function (): void {
    $this->app->instance(HealthChecker::class, new HealthChecker([new FakeCheck('database')]));

    // Asserted per directive rather than as one string: Symfony normalises and
    // reorders Cache-Control, so an exact match would break on a framework bump
    // without anything actually being wrong.
    $cacheControl = $this->getJson('/health')->headers->get('Cache-Control');

    expect($cacheControl)
        ->toContain('no-store')
        ->toContain('no-cache')
        ->toContain('must-revalidate');
});
