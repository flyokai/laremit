<?php

declare(strict_types=1);

namespace App\Domain\Events\Console;

use App\Domain\Events\Contracts\EventBuffer;
use Illuminate\Console\Command;

/**
 * Replay the stream's dead letters — entries that exhausted max_deliveries
 * or arrived undecodable — back onto the stream, after the defect that
 * poisoned them is fixed. Every consumer group sees the replayed entry
 * again; the groups that already handled it absorb the re-delivery through
 * their idempotency, which is the property that makes replay a safe, boring
 * operation instead of a careful one.
 */
final class ReplayDeadLettersCommand extends Command
{
    protected $signature = 'events:replay
        {--limit=100 : Max dead-letter entries to replay}';

    protected $description = 'Replay dead-lettered stream entries to every consumer group';

    public function handle(EventBuffer $buffer): int
    {
        $result = $buffer->replayDeadLetters(max(1, (int) $this->option('limit')));

        $this->info(sprintf('Replayed %d dead-lettered event(s).', $result['replayed']));

        if ($result['kept'] > 0) {
            $this->warn(sprintf(
                '%d entr%s could not be replayed (no decodable envelope) and remain dead-lettered.',
                $result['kept'],
                $result['kept'] === 1 ? 'y' : 'ies',
            ));
        }

        return self::SUCCESS;
    }
}
