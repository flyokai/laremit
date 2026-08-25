<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Events\Contracts\EventBuffer;
use App\Domain\Events\Stream\PendingEvent;
use App\Domain\Events\Support\Envelope;

/**
 * In-memory EventBuffer with the same observable semantics as the Redis
 * implementation: append marks ids seen, entries stay pending until acked,
 * unacked entries are claimable. Lets ingest and consumer logic be tested
 * without Redis; the real implementation is covered by the integration
 * suite in tests/Feature/Events.
 */
final class FakeEventBuffer implements EventBuffer
{
    public int $depth = 0;

    /** @var array<string, true> */
    public array $seen = [];

    /** @var list<Envelope> */
    public array $appended = [];

    /** @var array<string, array<string, PendingEvent>> group => id => event */
    public array $pending = [];

    /** @var array<string, list<string>> */
    public array $acked = [];

    /** @var list<array{PendingEvent, string}> */
    public array $deadLettered = [];

    private int $sequence = 0;

    public function depth(): int
    {
        return $this->depth;
    }

    public function depthAndSeen(array $eventIds): array
    {
        return [
            $this->depth,
            array_map(fn (string $id): bool => isset($this->seen[$id]), $eventIds),
        ];
    }

    public function append(array $envelopes): void
    {
        foreach ($envelopes as $envelope) {
            $this->appended[] = $envelope;
            $this->seen[$envelope->eventId] = true;
            $this->depth++;
        }
    }

    public function ensureGroup(string $group): void
    {
        $this->pending[$group] ??= [];
    }

    public function readNew(string $group, string $consumer, int $count, int $blockMs): array
    {
        $delivered = [];

        foreach (array_slice($this->appended, $this->cursor($group), $count) as $envelope) {
            $event = new PendingEvent('fake-'.$this->sequence++, $envelope);
            $this->pending[$group][$event->id] = $event;
            $delivered[] = $event;
        }

        return $delivered;
    }

    public function claimAbandoned(string $group, string $consumer, int $minIdleMs, int $count): array
    {
        return array_values(array_slice($this->pending[$group] ?? [], 0, $count));
    }

    public function ack(string $group, array $ids): void
    {
        foreach ($ids as $id) {
            unset($this->pending[$group][$id]);
            $this->acked[$group][] = $id;
        }
    }

    public function deadLetter(PendingEvent $event, string $reason): void
    {
        $this->deadLettered[] = [$event, $reason];
    }

    public function info(): array
    {
        return ['stream' => 'fake', 'depth' => $this->depth, 'maxlen' => 0, 'groups' => [], 'dead_letter' => count($this->deadLettered)];
    }

    private function cursor(string $group): int
    {
        return count($this->acked[$group] ?? []) + count($this->pending[$group] ?? []);
    }
}
