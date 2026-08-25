<?php

declare(strict_types=1);

namespace App\Domain\Events\Ingestion;

use App\Domain\Events\Contracts\EventBuffer;
use App\Domain\Events\Enums\EventStatus;
use App\Domain\Events\Support\Envelope;
use App\Domain\Events\Support\EnvelopeValidator;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * The ingest hot path: validate envelopes, consult backpressure once, dedup,
 * append. Two Redis round trips per batch (XLEN+MGET pipelined, then
 * XADD+SET pipelined), no database, no framework validator.
 */
final readonly class Ingestor
{
    public function __construct(
        private EventBuffer $buffer,
        private EnvelopeValidator $validator,
        private int $shedAnalyticsAbove,
        private int $rejectAllAbove,
        private int $retryAfterSeconds,
    ) {
        self::assertSaneThresholds($shedAnalyticsAbove, $rejectAllAbove);
    }

    /**
     * Backpressure must engage in order — shed first, reject second — and
     * reject must engage before the stream's MAXLEN could trim an unconsumed
     * entry. The maxlen half of that invariant is asserted where maxlen is
     * known (the service provider); see ADR-003.
     */
    public static function assertSaneThresholds(int $shedAbove, int $rejectAbove, ?int $maxlen = null): void
    {
        if ($shedAbove >= $rejectAbove) {
            throw new InvalidArgumentException(
                'events.backpressure: shed_analytics_above must be below reject_all_above.'
            );
        }

        if ($maxlen !== null && $rejectAbove >= $maxlen) {
            throw new InvalidArgumentException(
                'events.backpressure: reject_all_above must be below stream.maxlen, '
                .'or trimming can reach unconsumed events (ADR-003).'
            );
        }
    }

    /**
     * @param  list<mixed>  $rawEvents
     */
    public function ingest(array $rawEvents): IngestResult
    {
        $receivedAt = CarbonImmutable::now()->utc();

        /** @var array<int, array{event_id: string|null, status: EventStatus, errors?: array<string, string>}> $rows */
        $rows = [];
        /** @var array<int, Envelope> $envelopes */
        $envelopes = [];
        /** @var array<string, true> $inBatch */
        $inBatch = [];

        foreach ($rawEvents as $index => $raw) {
            $errors = $this->validator->errors($raw, $receivedAt);

            if ($errors !== []) {
                $rows[$index] = [
                    'event_id' => $this->echoedId($raw),
                    'status' => EventStatus::Invalid,
                    'errors' => $errors,
                ];

                continue;
            }

            /** @var array<string, mixed> $raw */
            $envelope = $this->validator->toEnvelope($raw, $receivedAt);

            // The same event_id twice in one batch is already a duplicate;
            // the second occurrence must not reach the stream.
            if (isset($inBatch[$envelope->eventId])) {
                $rows[$index] = ['event_id' => $envelope->eventId, 'status' => EventStatus::Duplicate];

                continue;
            }

            $inBatch[$envelope->eventId] = true;
            $envelopes[$index] = $envelope;
        }

        [$depth, $seen] = $this->buffer->depthAndSeen(
            array_map(static fn (Envelope $e): string => $e->eventId, array_values($envelopes)),
        );

        if ($depth >= $this->rejectAllAbove) {
            return IngestResult::rejected($this->retryAfterSeconds);
        }

        $shedding = $depth >= $this->shedAnalyticsAbove;
        $append = [];
        $position = 0;

        foreach ($envelopes as $index => $envelope) {
            if ($seen[$position++] ?? false) {
                $rows[$index] = ['event_id' => $envelope->eventId, 'status' => EventStatus::Duplicate];

                continue;
            }

            if ($shedding && $envelope->priority->sheddable()) {
                $rows[$index] = ['event_id' => $envelope->eventId, 'status' => EventStatus::Shed];

                continue;
            }

            $append[] = $envelope;
            $rows[$index] = ['event_id' => $envelope->eventId, 'status' => EventStatus::Accepted];
        }

        $this->buffer->append($append);

        ksort($rows);

        return IngestResult::of(array_values($rows), $this->retryAfterSeconds);
    }

    private function echoedId(mixed $raw): ?string
    {
        $id = is_array($raw) ? ($raw['event_id'] ?? null) : null;

        return is_string($id) && strlen($id) <= 64 ? $id : null;
    }
}
