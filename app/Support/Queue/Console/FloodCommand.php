<?php

declare(strict_types=1);

namespace App\Support\Queue\Console;

use App\Support\Queue\Jobs\SyntheticEventJob;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Support\Str;

/**
 * The Phase 6 deliverable's blunt instrument: pile a mountain of jobs onto
 * one lane, then go measure that the payments lane did not notice. This is
 * what a rogue producer (or a product launch) looks like from the queue's
 * point of view.
 *
 * The jobs are pushed as raw RPUSHes of a real payload template, bypassing
 * the Queue facade on purpose, for two reasons. Speed: a pipelined
 * multi-value RPUSH moves a million payloads in seconds, where a million
 * Queue::push round-trips would take minutes and measure the flooder, not
 * the flood. Memory: Horizon's push-time bookkeeping stores a copy of every
 * payload on the same Redis instance, which would double the flood's
 * footprint before a single job ran. The jobs themselves are fully real —
 * workers pop them, run them, and Horizon meters them at processing time.
 *
 * The template comes from one genuine push to a scratch queue no supervisor
 * drains, so the payload shape is whatever the framework actually produces,
 * not a hand-maintained imitation.
 */
final class FloodCommand extends Command
{
    protected $signature = 'queue:flood
        {count=1000000 : Number of synthetic jobs to push}
        {--lane=events : Lane (queue connection) to flood}
        {--chunk=5000 : Payloads per RPUSH}';

    protected $description = 'Flood one queue lane with synthetic no-op jobs (isolation demo, ADR-007)';

    private const TEMPLATE_QUEUE = 'flood-template';

    public function handle(QueueFactory $queue, RedisFactory $redis, Repository $config): int
    {
        if ((bool) $this->laravel->isProduction()) {
            $this->error('queue:flood is a load-test tool; refusing to run in production.');

            return self::FAILURE;
        }

        $lane = (string) $this->option('lane');
        $laneConfig = (array) $config->get("queue.connections.{$lane}");

        if (($laneConfig['driver'] ?? null) !== 'redis') {
            $this->error("Lane [{$lane}] is not a redis queue connection; nothing to flood.");

            return self::FAILURE;
        }

        $count = max(1, (int) $this->argument('count'));
        $chunk = max(1, (int) $this->option('chunk'));
        $queueName = (string) $laneConfig['queue'];
        $key = 'queues:'.$queueName;

        // One real push produces the template; the scratch queue keeps it
        // out of any live worker's mouth while we read it back.
        $queue->connection($lane)->pushOn(self::TEMPLATE_QUEUE, new SyntheticEventJob);

        $conn = $redis->connection((string) $laneConfig['connection']);
        $template = $conn->command('lpop', ['queues:'.self::TEMPLATE_QUEUE]);

        if (! is_string($template)) {
            $this->error('Could not read back the payload template.');

            return self::FAILURE;
        }

        /** @var array<string, mixed> $payload */
        $payload = json_decode($template, true, flags: JSON_THROW_ON_ERROR);
        $run = Str::lower(Str::random(8));

        $this->info(sprintf('Flooding [%s:%s] with %s jobs…', $lane, $queueName, number_format($count)));

        $started = microtime(true);
        $buffer = [];

        for ($i = 1; $i <= $count; $i++) {
            // Unique ids per job: uuid is identity everywhere downstream
            // (Horizon metering, maxExceptions tracking), and reusing one a
            // million times would fold every job into the same record.
            $payload['uuid'] = $payload['id'] = sprintf('flood-%s-%07d', $run, $i);
            $buffer[] = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

            if (count($buffer) === $chunk) {
                $conn->command('rpush', [$key, ...$buffer]);
                $buffer = [];

                if ($i % 100_000 === 0) {
                    $this->line(sprintf('  %s pushed (%.1fs)', number_format($i), microtime(true) - $started));
                }
            }
        }

        if ($buffer !== []) {
            $conn->command('rpush', [$key, ...$buffer]);
        }

        $elapsed = microtime(true) - $started;
        $depth = (int) $conn->command('llen', [$key]);

        $this->info(sprintf(
            'Pushed %s jobs in %.1fs (%s jobs/sec). [%s] depth is now %s.',
            number_format($count),
            $elapsed,
            number_format((int) ($count / max($elapsed, 0.001))),
            $queueName,
            number_format($depth),
        ));

        return self::SUCCESS;
    }
}
