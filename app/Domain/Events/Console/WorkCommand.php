<?php

declare(strict_types=1);

namespace App\Domain\Events\Console;

use App\Domain\Events\Contracts\Consumer;
use App\Domain\Events\Contracts\EventBuffer;
use App\Domain\Events\Stream\PendingEvent;
use App\Domain\Events\Support\Envelope;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use LogicException;
use Throwable;

/**
 * A consumer-group worker: claim what crashed peers abandoned, read new
 * entries, apply, ack. One process per invocation; run several with distinct
 * --consumer names to scale a group out.
 *
 * The crash contract: nothing is acked until apply() returns, so a kill at
 * any point leaves the in-flight batch in this consumer's PEL, where another
 * worker's XAUTOCLAIM picks it up after claim-idle. Redelivery re-applies
 * the whole batch; the consumers are idempotent, so the net effect is
 * exactly-once. That pair of properties — no loss, no double-count — is the
 * Phase 2 chaos deliverable.
 */
final class WorkCommand extends Command
{
    protected $signature = 'events:work
        {group : Consumer group to run: archive, projections or reactions}
        {--consumer= : Consumer name within the group (default host:pid)}
        {--batch=100 : Max entries per read}
        {--block=5000 : XREADGROUP block milliseconds}
        {--claim-idle= : Min idle ms before stealing another consumer\'s pending entries}
        {--max-events=0 : Exit after processing this many events (0 = forever)}
        {--once : Run a single claim+read iteration and exit}';

    protected $description = 'Run one event stream consumer-group worker';

    private bool $shouldStop = false;

    public function handle(EventBuffer $buffer): int
    {
        $group = (string) $this->argument('group');

        /** @var array<string, class-string<Consumer>> $groups */
        $groups = (array) config('events.consumers.groups', []);

        if (! isset($groups[$group])) {
            $this->error(sprintf('Unknown group [%s]; expected one of: %s.', $group, implode(', ', array_keys($groups))));

            return self::INVALID;
        }

        /** @var Consumer $consumer */
        $consumer = $this->laravel->make($groups[$group]);

        $name = (string) ($this->option('consumer') ?: gethostname().':'.getmypid());
        $batch = max(1, (int) $this->option('batch'));
        $block = max(0, (int) $this->option('block'));
        $claimIdle = (int) ($this->option('claim-idle') ?? config('events.consumers.claim_idle_ms'));
        $maxEvents = max(0, (int) $this->option('max-events'));
        $maxDeliveries = (int) config('events.consumers.max_deliveries');

        // Finish the in-flight batch, ack it, exit. An unacked batch is not
        // lost either way — it would just wait out claim-idle elsewhere.
        $this->trap([SIGTERM, SIGINT], function (): void {
            $this->shouldStop = true;
        });

        $buffer->ensureGroup($group);

        $this->info(sprintf('Consuming [%s] as [%s].', $group, $name));

        $processed = 0;

        do {
            $claimed = $buffer->claimAbandoned($group, $name, $claimIdle, $batch);
            $processed += $this->drain($buffer, $consumer, $group, $claimed, $maxDeliveries);

            if ($this->shouldStop) {
                break;
            }

            $fresh = $buffer->readNew($group, $name, $batch, $block);
            $processed += $this->drain($buffer, $consumer, $group, $fresh, $maxDeliveries);
        } while (! $this->shouldStop
            && ! (bool) $this->option('once')
            && ($maxEvents === 0 || $processed < $maxEvents));

        $this->info(sprintf('Stopping after %d events.', $processed));

        return self::SUCCESS;
    }

    /**
     * @param  list<PendingEvent>  $events
     *
     * @phpstan-impure
     */
    private function drain(EventBuffer $buffer, Consumer $consumer, string $group, array $events, int $maxDeliveries): int
    {
        if ($events === []) {
            return 0;
        }

        $dead = [];
        $live = [];

        foreach ($events as $event) {
            if ($event->envelope === null) {
                $dead[$event->id] = [$event, 'undecodable envelope'];
            } elseif ($event->deliveries > $maxDeliveries) {
                $dead[$event->id] = [$event, sprintf('poison: %d failed deliveries', $event->deliveries)];
            } else {
                $live[] = $event;
            }
        }

        foreach ($dead as [$event, $reason]) {
            $buffer->deadLetter($event, $reason);
            Log::warning('Event dead-lettered.', ['group' => $group, 'id' => $event->id, 'reason' => $reason]);
        }

        // Dead-lettered ids are acked — they left the group by another door.
        $buffer->ack($group, array_keys($dead));

        if ($live === []) {
            return 0;
        }

        try {
            $consumer->apply(array_map(
                static fn (PendingEvent $event): Envelope => $event->envelope ?? throw new LogicException('unreachable'),
                $live,
            ));
        } catch (Throwable $e) {
            // No ack: the whole batch stays pending and is redelivered (to
            // this consumer or, if we die, to whoever claims it). Repeated
            // failure walks each entry toward the max_deliveries dead letter.
            Log::error('Consumer batch failed; leaving events pending.', [
                'group' => $group,
                'count' => count($live),
                'exception' => $e,
            ]);

            return count($dead);
        }

        $buffer->ack($group, array_map(static fn (PendingEvent $event): string => $event->id, $live));

        return count($dead) + count($live);
    }
}
