<?php

declare(strict_types=1);

namespace App\Domain\Events\Stream;

use App\Domain\Events\Contracts\EventBuffer;
use App\Domain\Events\Support\Envelope;
use App\Domain\Events\Support\PhpRedis;
use Illuminate\Contracts\Redis\Factory;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * Redis Streams implementation of the buffer (ADR-003).
 *
 * One stream, one consumer group per pipeline stage, XACK on completion and
 * XAUTOCLAIM for crash recovery. Dedup keys live on the same noeviction
 * instance as the stream: an evicted dedup key silently re-admits duplicates,
 * which makes it queue-class data, not cache-class (ADR-002).
 */
final readonly class RedisEventStream implements EventBuffer
{
    public function __construct(
        private Factory $redis,
        private string $connection,
        private string $streamKey,
        private string $dedupPrefix,
        private int $dedupTtl,
        private int $maxlen,
        private string $deadLetterKey,
    ) {}

    public function depth(): int
    {
        return (int) $this->conn()->xlen($this->streamKey);
    }

    public function depthAndSeen(array $eventIds): array
    {
        if ($eventIds === []) {
            return [$this->depth(), []];
        }

        $keys = array_map(fn (string $id): string => $this->dedupPrefix.$id, $eventIds);

        /** @var array{int, array<int, mixed>} $replies */
        $replies = PhpRedis::pipeline($this->conn(), function ($pipe) use ($keys): void {
            $pipe->xlen($this->streamKey);
            $pipe->mget($keys);
        });

        $seen = array_map(
            static fn (mixed $value): bool => $value !== false && $value !== null,
            array_values($replies[1]),
        );

        return [(int) $replies[0], $seen];
    }

    public function append(array $envelopes): void
    {
        if ($envelopes === []) {
            return;
        }

        // XADD immediately followed by the dedup SET, per event, in one
        // pipeline. If the connection dies between the two, the event is in
        // the stream but unmarked — a retry re-appends it and the idempotent
        // consumers absorb the duplicate. The reverse order would mark an
        // event as seen without it ever reaching the stream: a silent loss.
        PhpRedis::pipeline($this->conn(), function ($pipe) use ($envelopes): void {
            foreach ($envelopes as $envelope) {
                $pipe->xadd($this->streamKey, '*', ['e' => $envelope->toJson()], $this->maxlen, true);
                $pipe->set($this->dedupPrefix.$envelope->eventId, '1', ['NX', 'EX' => $this->dedupTtl]);
            }
        });
    }

    public function ensureGroup(string $group): void
    {
        try {
            $existing = $this->conn()->xinfo('GROUPS', $this->streamKey);
        } catch (Throwable) {
            $existing = false;
        }

        if (is_array($existing)) {
            foreach ($existing as $info) {
                if (is_array($info) && ($info['name'] ?? null) === $group) {
                    return;
                }
            }
        }

        try {
            // '0' not '$': a group created after events were ingested must
            // still see them. MKSTREAM so workers can start before ingest.
            $this->conn()->xgroup('CREATE', $this->streamKey, $group, '0', true);
        } catch (Throwable $e) {
            if (! str_contains($e->getMessage(), 'BUSYGROUP')) {
                throw $e;
            }
        }
    }

    public function readNew(string $group, string $consumer, int $count, int $blockMs): array
    {
        $reply = $this->conn()->xreadgroup($group, $consumer, [$this->streamKey => '>'], $count, $blockMs);

        if (! is_array($reply) || $reply === []) {
            return [];
        }

        /** @var array<string, array<string, string>> $entries */
        $entries = reset($reply);

        return $this->hydrate($entries, static fn (): int => 1);
    }

    public function claimAbandoned(string $group, string $consumer, int $minIdleMs, int $count): array
    {
        $reply = $this->conn()->xautoclaim($this->streamKey, $group, $consumer, $minIdleMs, '0-0', $count);

        if (! is_array($reply)) {
            return [];
        }

        /** @var array<string, array<string, string>|null> $entries */
        $entries = $reply[1] ?? [];

        // Redis 7 reports ids whose entries were trimmed away while pending.
        // That is the ADR-003 invariant (reject_all_above < maxlen) failing
        // in production — it must be loud.
        $deleted = $reply[2] ?? [];

        if (is_array($deleted) && $deleted !== []) {
            Log::error('Event stream trimmed entries that were still pending.', [
                'group' => $group,
                'ids' => $deleted,
            ]);
        }

        // XAUTOCLAIM can return null entries for ids deleted mid-claim.
        $entries = array_filter($entries, static fn (mixed $fields): bool => is_array($fields));

        if ($entries === []) {
            return [];
        }

        $deliveries = $this->deliveryCounts($group, $consumer, count($entries));

        return $this->hydrate($entries, static fn (string $id): int => $deliveries[$id] ?? 1);
    }

    public function ack(string $group, array $ids): void
    {
        if ($ids === []) {
            return;
        }

        $this->conn()->xack($this->streamKey, $group, $ids);
    }

    public function deadLetter(PendingEvent $event, string $reason): void
    {
        $this->conn()->lpush($this->deadLetterKey, json_encode([
            'id' => $event->id,
            'reason' => $reason,
            'deliveries' => $event->deliveries,
            'envelope' => $event->envelope?->toArray(),
            'raw' => $event->raw,
        ], JSON_THROW_ON_ERROR));
    }

    public function replayDeadLetters(int $limit): array
    {
        $conn = $this->conn();
        $pops = min($limit, (int) $conn->llen($this->deadLetterKey));

        $replayed = 0;
        $kept = 0;

        // RPOP takes the oldest (deadLetter LPUSHes); an unreplayable entry
        // goes back on the head, which this bounded loop never revisits.
        for ($i = 0; $i < $pops; $i++) {
            $raw = $conn->rpop($this->deadLetterKey);

            if (! is_string($raw)) {
                break;
            }

            $envelope = $this->decodeDeadLetter($raw);

            if ($envelope === null) {
                $conn->lpush($this->deadLetterKey, $raw);
                $kept++;

                continue;
            }

            // A fresh stream entry: the delivery counter starts over, so a
            // still-poisonous event earns its max_deliveries again before
            // coming back here — replay can loop, but only by hand.
            $this->append([$envelope]);
            $replayed++;
        }

        return ['replayed' => $replayed, 'kept' => $kept];
    }

    public function info(): array
    {
        try {
            $groups = $this->conn()->xinfo('GROUPS', $this->streamKey);
        } catch (Throwable) {
            $groups = [];
        }

        return [
            'stream' => $this->streamKey,
            'depth' => $this->depth(),
            'maxlen' => $this->maxlen,
            'groups' => is_array($groups) ? $groups : [],
            'dead_letter' => (int) $this->conn()->llen($this->deadLetterKey),
        ];
    }

    private function conn(): PhpRedisConnection
    {
        return PhpRedis::connection($this->redis, $this->connection);
    }

    /**
     * A dead-letter record's envelope, if it still decodes. Records written
     * for undecodable stream entries carry no envelope at all; anything else
     * malformed here means the dead-letter store itself was corrupted.
     */
    private function decodeDeadLetter(string $raw): ?Envelope
    {
        try {
            $record = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);

            if (! is_array($record) || ! is_array($record['envelope'] ?? null)) {
                return null;
            }

            return Envelope::fromJson(json_encode($record['envelope'], JSON_THROW_ON_ERROR));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, array<string, string>>  $entries
     * @param  callable(string): int  $deliveriesFor
     * @return list<PendingEvent>
     */
    private function hydrate(array $entries, callable $deliveriesFor): array
    {
        $events = [];

        foreach ($entries as $id => $fields) {
            try {
                $envelope = Envelope::fromJson($fields['e'] ?? '');
            } catch (InvalidArgumentException) {
                $envelope = null;
            }

            $events[] = new PendingEvent($id, $envelope, $deliveriesFor($id), $envelope === null ? $fields : []);
        }

        return $events;
    }

    /**
     * Delivery counts for entries this consumer currently holds. Only needed
     * on the claim path — a freshly read entry is on its first delivery.
     *
     * @return array<string, int>
     */
    private function deliveryCounts(string $group, string $consumer, int $count): array
    {
        $pending = $this->conn()->xpending($this->streamKey, $group, '-', '+', $count, $consumer);

        if (! is_array($pending)) {
            return [];
        }

        $counts = [];

        foreach ($pending as $row) {
            if (is_array($row) && isset($row[0], $row[3])) {
                $counts[(string) $row[0]] = (int) $row[3];
            }
        }

        return $counts;
    }
}
