<?php

declare(strict_types=1);

namespace App\Domain\Outbox;

use App\Domain\Events\Enums\EventStatus;
use App\Domain\Events\Ingestion\Ingestor;
use App\Domain\Outbox\Models\OutboxMessage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The read half of the outbox: claim committed rows, republish them into the
 * event pipeline, mark them dispatched — in that order, on purpose.
 *
 * Parallel safety is FOR UPDATE SKIP LOCKED: concurrent relays never fight
 * over rows, they interleave over disjoint sets, so scaling out is starting
 * another process. (SQLite ignores the lock clause; real lock semantics are
 * a MySQL-only fact, verified on the live stack — tech-debt #2.)
 *
 * The crash contract — the Phase 5 chaos deliverable: publish happens inside
 * the claim transaction, mark-dispatched commits it. Die between the two and
 * the rows unlock still pending, so a later pass re-publishes them — with the
 * SAME deterministic event ids, which the ingest dedup window collapses, and
 * past that window the consumers' own idempotency absorbs. At-least-once on
 * the wire, exactly-once in effect; the one order that can LOSE an event
 * (mark first, publish second) is the one this class cannot express.
 *
 * The relay is deliberately just another producer: it submits raw events to
 * the same Ingestor as a client SDK and honours the same verdicts — including
 * backpressure, which for the relay simply means "the facts wait in the
 * table"; nothing is shed because domain events are operational priority.
 */
final readonly class OutboxRelay
{
    public function __construct(private Ingestor $ingestor) {}

    /**
     * Relay one batch. Returns what happened, for the command's log line.
     *
     * @return array{claimed: int, dispatched: int, duplicates: int, dead: int, rejected: bool, retry_after: int}
     */
    public function relayBatch(int $batch): array
    {
        return DB::transaction(function () use ($batch): array {
            /** @var list<OutboxMessage> $messages */
            $messages = OutboxMessage::query()
                ->whereNull('dispatched_at')
                ->whereNull('dead_lettered_at')
                ->where('available_at', '<=', CarbonImmutable::now())
                ->orderBy('id')
                ->limit($batch)
                ->lock('for update skip locked')
                ->get()
                ->all();

            if ($messages === []) {
                return ['claimed' => 0, 'dispatched' => 0, 'duplicates' => 0, 'dead' => 0, 'rejected' => false, 'retry_after' => 0];
            }

            $result = $this->ingestor->ingest(
                array_map(static fn (OutboxMessage $message): array => $message->envelopeInput(), $messages),
            );

            if ($result->rejected) {
                // Backpressure. The rows stay pending — the outbox is the
                // buffer here, and it is durable; just come back later.
                return [
                    'claimed' => count($messages),
                    'dispatched' => 0,
                    'duplicates' => 0,
                    'dead' => 0,
                    'rejected' => true,
                    'retry_after' => $result->retryAfterSeconds,
                ];
            }

            $now = CarbonImmutable::now();
            $dispatched = [];
            $duplicates = 0;
            $dead = [];

            foreach ($messages as $index => $message) {
                $row = $result->rows[$index];

                switch ($row['status']) {
                    case EventStatus::Accepted:
                        $dispatched[] = $message->id;
                        break;

                    case EventStatus::Duplicate:
                        // Already on the stream — a previous pass published it
                        // and died before this line. Marking it dispatched IS
                        // the recovery.
                        $dispatched[] = $message->id;
                        $duplicates++;
                        break;

                    case EventStatus::Invalid:
                        // Deterministic failure: retrying cannot fix a shape.
                        // Park it out of the claim so it cannot poison every
                        // future batch; outbox:replay re-arms it after a fix.
                        $dead[$message->id] = json_encode($row['errors'] ?? [], JSON_UNESCAPED_SLASHES);
                        break;

                    case EventStatus::Shed:
                        // Unreachable while domain events are operational
                        // priority; if it ever fires, shedding rules changed
                        // under us. Leave the row pending and say so loudly.
                        Log::error('Outbox message was shed by ingest; domain events must be operational priority.', [
                            'outbox_message_id' => $message->id,
                            'type' => $message->type,
                        ]);
                        break;
                }
            }

            if ($dispatched !== []) {
                OutboxMessage::query()->whereIn('id', $dispatched)->update(['dispatched_at' => $now]);
            }

            foreach ($dead as $id => $error) {
                OutboxMessage::query()->whereKey($id)->update([
                    'dead_lettered_at' => $now,
                    'last_error' => mb_substr((string) $error, 0, 255),
                ]);

                Log::error('Outbox message failed envelope validation; dead-lettered.', [
                    'outbox_message_id' => $id,
                    'errors' => $error,
                ]);
            }

            return [
                'claimed' => count($messages),
                'dispatched' => count($dispatched),
                'duplicates' => $duplicates,
                'dead' => count($dead),
                'rejected' => false,
                'retry_after' => 0,
            ];
        });
    }
}
