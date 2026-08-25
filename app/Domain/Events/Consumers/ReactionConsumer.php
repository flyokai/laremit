<?php

declare(strict_types=1);

namespace App\Domain\Events\Consumers;

use App\Domain\Events\Contracts\Consumer;
use App\Domain\Events\Support\PhpRedis;
use App\Domain\Events\Support\SchemaRegistry;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Redis\Factory;

/**
 * Turns events into queued domain-reaction jobs on the (isolated) queue
 * Redis instance, where Horizon runs them.
 *
 * Delivery is at-least-once by choice: the reacted-marker is written AFTER
 * dispatch, so a crash between dispatch and marker re-dispatches on
 * redelivery. The alternative order (marker first) converts a crash into a
 * silently lost reaction — losing "charge the renewal" is strictly worse
 * than running an idempotent job twice. Reaction jobs must therefore be
 * idempotent; that contract is on the Consumer interface.
 *
 * The type => jobs map is read from config at apply time. It ships empty:
 * billing wires its reactions in Phase 3 (tech-debt #9).
 */
final readonly class ReactionConsumer implements Consumer
{
    public function __construct(
        private Dispatcher $bus,
        private Factory $redis,
        private Repository $config,
        private SchemaRegistry $schemas,
        private string $connection,
        private string $markerPrefix,
        private int $markerTtl,
    ) {}

    public function apply(array $envelopes): void
    {
        /** @var array<string, list<class-string>> $map */
        $map = (array) $this->config->get('events.reactions.map', []);

        if ($map === []) {
            return;
        }

        $conn = PhpRedis::connection($this->redis, $this->connection);

        foreach ($envelopes as $envelope) {
            $jobs = $map[$envelope->type] ?? [];

            if ($jobs === []) {
                continue;
            }

            $marker = $this->markerPrefix.$envelope->eventId;

            if ((int) $conn->exists($marker) === 1) {
                continue;
            }

            // Reactions consume the payload, so they get the current schema.
            $normalized = $this->schemas->normalize($envelope);

            foreach ($jobs as $jobClass) {
                $this->bus->dispatch(new $jobClass($normalized));
            }

            $conn->set($marker, '1', 'EX', $this->markerTtl);
        }
    }
}
