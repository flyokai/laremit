<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Queue\Events\QueueBusy;
use Illuminate\Support\Facades\Log;
use Laravel\Horizon\Events\LongWaitDetected;

/**
 * The alerting posture (ADR-007, tech-debt #19): wait time is the primary
 * signal, depth the backstop, both landing as critical logs until Phase 9
 * gives them a pager.
 */
it('logs critical when Horizon detects a long wait', function (): void {
    Log::shouldReceive('critical')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => $context['connection'] === 'payments'
            && $context['queue'] === 'payments'
            && $context['wait_seconds'] === 31);

    event(new LongWaitDetected('payments', 'payments', 31));
});

it('logs critical when queue:monitor finds a lane over its depth threshold', function (): void {
    Log::shouldReceive('critical')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => $context['connection'] === 'events'
            && $context['queue'] === 'events'
            && $context['depth'] === 1_000_000);

    event(new QueueBusy('events', 'events', 1_000_000));
});

it('schedules the depth watchdog for every lane, and horizon snapshots', function (): void {
    $commands = array_map(
        fn (ScheduledEvent $event): string => (string) $event->command,
        app(Schedule::class)->events(),
    );

    $monitors = array_values(array_filter($commands, fn (string $c): bool => str_contains($c, 'queue:monitor')));

    expect($monitors)->toHaveCount(2)
        // Two invocations because one --max fits all lanes like one shoe:
        // the payments threshold is two orders of magnitude stricter.
        ->and(implode(' ', $monitors))->toContain('payments:payments', 'events:events,bulk:bulk,bulk:default')
        ->and(array_filter($commands, fn (string $c): bool => str_contains($c, 'horizon:snapshot')))->toHaveCount(1);
});
