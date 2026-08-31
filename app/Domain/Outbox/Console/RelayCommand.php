<?php

declare(strict_types=1);

namespace App\Domain\Outbox\Console;

use App\Domain\Outbox\OutboxRelay;
use Illuminate\Console\Command;

/**
 * The relay worker loop. One process per invocation; SKIP LOCKED makes
 * running several of them coordination-free, so scaling out is
 * `docker compose up -d --scale relay-outbox=2`.
 *
 * Kill-safety is the relay's whole job description: SIGTERM finishes the
 * in-flight batch and exits; SIGKILL mid-batch rolls the claim back and a
 * peer (or the next start) re-relays it — see OutboxRelay for why that
 * cannot lose or double-deliver.
 */
final class RelayCommand extends Command
{
    protected $signature = 'outbox:relay
        {--batch= : Messages per claim (default: outbox.relay.batch)}
        {--sleep-ms= : Idle sleep between empty polls (default: outbox.relay.sleep_ms)}
        {--once : Relay a single batch and exit}';

    protected $description = 'Run one outbox relay worker';

    private bool $shouldStop = false;

    public function handle(OutboxRelay $relay): int
    {
        $batch = max(1, (int) ($this->option('batch') ?: config('outbox.relay.batch')));
        $sleepMs = max(1, (int) ($this->option('sleep-ms') ?: config('outbox.relay.sleep_ms')));

        // Finish the in-flight batch, then exit. An interrupted batch is not
        // lost either way — its claim rolls back for the next pass.
        $this->trap([SIGTERM, SIGINT], function (): void {
            $this->shouldStop = true;
        });

        $this->info(sprintf('Relaying outbox messages in batches of %d.', $batch));

        do {
            $result = $relay->relayBatch($batch);

            if ($result['rejected']) {
                $this->warn(sprintf(
                    'Stream is applying backpressure; %d messages wait in the outbox. Retrying in %ds.',
                    $result['claimed'],
                    $result['retry_after'],
                ));
                $this->pause($result['retry_after'] * 1000);
            } elseif ($result['claimed'] === 0) {
                $this->pause($sleepMs);
            } else {
                $this->line(sprintf(
                    'Relayed %d (%d fresh, %d already on the stream, %d dead-lettered).',
                    $result['claimed'],
                    $result['dispatched'] - $result['duplicates'],
                    $result['duplicates'],
                    $result['dead'],
                ));
            }
        } while (! $this->shouldStop && ! (bool) $this->option('once'));

        $this->info('Stopping.');

        return self::SUCCESS;
    }

    /** Sleep in slices so a signal still stops us promptly. */
    private function pause(int $milliseconds): void
    {
        $until = hrtime(true) + $milliseconds * 1_000_000;

        while (! $this->shouldStop && hrtime(true) < $until) {
            usleep(50_000);
        }
    }
}
