<?php

declare(strict_types=1);

use App\Support\Health\Check;
use App\Support\Health\HealthChecker;
use Tests\Support\FakeCheck;

it('reports ok when every check passes', function (): void {
    $report = (new HealthChecker([
        new FakeCheck('database', detail: ['driver' => 'mysql']),
        new FakeCheck('redis:queue', detail: ['maxmemory_policy' => 'noeviction']),
    ]))->run();

    expect($report->healthy())->toBeTrue()
        ->and($report->toArray()['status'])->toBe('ok')
        ->and($report->toArray()['checks']['database']['detail'])->toBe(['driver' => 'mysql']);
});

it('records a throwing check as failing without stopping the others', function (): void {
    $report = (new HealthChecker([
        new FakeCheck('database', throws: 'connection refused'),
        new FakeCheck('redis:cache'),
    ]))->run();

    $checks = $report->toArray()['checks'];

    expect($report->healthy())->toBeFalse()
        ->and($checks['database']['status'])->toBe('failing')
        ->and($checks['database']['error'])->toBe('connection refused')
        // The whole point: a dead dependency must not mask a healthy one.
        ->and($checks['redis:cache']['status'])->toBe('ok');
});

it('times every check', function (): void {
    $report = (new HealthChecker([new FakeCheck('database')]))->run();

    expect($report->results[0]->durationMs)->toBeGreaterThanOrEqual(0.0);
});

it('is healthy when there is nothing to check', function (): void {
    expect((new HealthChecker([]))->run()->healthy())->toBeTrue();
});

it('accepts any Check implementation', function (): void {
    expect(new FakeCheck('x'))->toBeInstanceOf(Check::class);
});
