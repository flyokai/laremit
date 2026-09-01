<?php

declare(strict_types=1);

use App\Domain\Billing\Enums\Store;
use App\Domain\Billing\Jobs\ChargeJob;
use App\Domain\Billing\Jobs\ProcessWebhookEvent;
use App\Domain\Billing\Jobs\ProjectBillingMetric;
use App\Domain\Events\Enums\Priority;
use App\Domain\Events\Support\Envelope;
use App\MockPsp\Jobs\DeliverPspWebhook;
use App\MockStores\Jobs\DeliverStoreNotification;
use App\Support\Queue\Jobs\SyntheticEventJob;
use Carbon\CarbonImmutable;
use Illuminate\Queue\Middleware\ThrottlesExceptions;

/**
 * ADR-007's invariants, as executable statements. These tests read config
 * and job classes, not Redis: they are the guard rail that stops a future
 * "just bump the timeout" from quietly recreating the double-charge bug.
 */

/** @return array<string, array<string, mixed>> supervisor name => effective config, per environment */
function horizonSupervisors(): array
{
    $defaults = (array) config('horizon.defaults');
    $merged = [];

    foreach ((array) config('horizon.environments') as $environment => $overrides) {
        foreach ($defaults as $name => $supervisor) {
            $merged["{$environment}.{$name}"] = array_merge((array) $supervisor, (array) ($overrides[$name] ?? []));
        }
    }

    return $merged;
}

/** @return list<array{0: object, 1: string, 2: string}> job instance, expected connection, expected queue */
function lanePinnedJobs(): array
{
    $envelope = new Envelope(
        eventId: 'evt-topology',
        type: 'billing.payment.succeeded',
        schemaVersion: 2,
        occurredAt: CarbonImmutable::now(),
        userId: 1,
        product: 'edtech',
        priority: Priority::Operational,
        payload: [],
        receivedAt: CarbonImmutable::now(),
    );

    return [
        [new ChargeJob(1), 'payments', 'payments'],
        [new ProcessWebhookEvent(1), 'payments', 'payments'],
        [new ProjectBillingMetric($envelope), 'events', 'events'],
        [new DeliverPspWebhook([]), 'bulk', 'bulk'],
        [new DeliverStoreNotification(Store::Psp, '{}', 'evt-topology'), 'bulk', 'bulk'],
        [new SyntheticEventJob, 'events', 'events'],
    ];
}

it('keeps every supervisor timeout under its connection retry_after, in every environment', function (): void {
    // THE rule (module 5): a reserved job reappears after retry_after
    // seconds whether or not its worker is alive. A supervisor allowed to
    // run jobs longer than that hands the same charge to a second worker.
    foreach (horizonSupervisors() as $name => $supervisor) {
        $connection = (string) $supervisor['connection'];
        $retryAfter = config("queue.connections.{$connection}.retry_after");

        expect($retryAfter)
            ->toBeInt("Supervisor [{$name}] points at connection [{$connection}] which has no retry_after.")
            ->toBeGreaterThan(
                (int) $supervisor['timeout'],
                "Supervisor [{$name}]: timeout {$supervisor['timeout']}s must stay under [{$connection}] retry_after {$retryAfter}s.",
            );
    }
});

it('drains every lane queue, and default, with exactly one supervisor', function (): void {
    $watched = [];

    foreach ((array) config('horizon.defaults') as $name => $supervisor) {
        foreach ((array) $supervisor['queue'] as $queue) {
            $pair = $supervisor['connection'].':'.$queue;

            $drainedBy = $watched[$pair] ?? null;

            expect($drainedBy)->toBeNull("Queue [{$pair}] is drained by both [{$drainedBy}] and [{$name}].");

            $watched[$pair] = $name;
        }
    }

    expect(array_keys($watched))->toEqualCanonicalizing([
        'payments:payments',
        'events:events',
        'bulk:bulk',
        'bulk:default', // unclassified work is bulk by definition (ADR-007)
    ]);
});

it('has a wait-time alert threshold for every supervised queue, strictest on payments', function (): void {
    $waits = (array) config('horizon.waits');

    foreach ((array) config('horizon.defaults') as $supervisor) {
        foreach ((array) $supervisor['queue'] as $queue) {
            expect($waits)->toHaveKey($supervisor['connection'].':'.$queue);
        }
    }

    // The asymmetry IS the topology: a payment waiting is an incident,
    // bulk waiting is Tuesday.
    foreach ($waits as $pair => $threshold) {
        expect($waits['payments:payments'])->toBeLessThanOrEqual(
            $threshold,
            "The payments wait threshold must be the strictest; [{$pair}] alerts sooner.",
        );
    }
});

it('pins every job to its lane, with a timeout that fits under the lane retry_after', function (): void {
    foreach (lanePinnedJobs() as [$job, $connection, $queue]) {
        $retryAfter = (int) config("queue.connections.{$connection}.retry_after");

        expect($job->connection)->toBe($connection, $job::class.' rides the wrong connection.')
            ->and($job->queue)->toBe($queue, $job::class.' rides the wrong queue.')
            ->and($job->timeout)->toBeLessThan($retryAfter, $job::class." timeout must stay under [{$connection}] retry_after.");
    }
});

it('gives breaker-guarded jobs a deadline, not an attempt count', function (): void {
    // ThrottlesExceptions releases jobs while the circuit is open, and every
    // release burns an attempt — a $tries budget would be spent by the
    // breaker itself, failing jobs that never reached the provider.
    foreach ([new ChargeJob(1), new ProcessWebhookEvent(1)] as $job) {
        $breakers = array_filter($job->middleware(), fn (object $m): bool => $m instanceof ThrottlesExceptions);

        expect($breakers)->toHaveCount(1, $job::class.' must carry exactly one circuit breaker.')
            ->and(isset($job->tries))->toBeFalse($job::class.' must not cap attempts; the deadline bounds it.')
            ->and($job->retryUntil()->getTimestamp())->toBeGreaterThan(
                CarbonImmutable::now()->addMinutes(5)->getTimestamp(),
                $job::class.' must retry on a deadline.',
            );
    }
});
