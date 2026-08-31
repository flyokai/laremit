<?php

declare(strict_types=1);

namespace App\Domain\Outbox\Console;

use App\Domain\Outbox\Models\OutboxMessage;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Re-arm dead-lettered outbox messages after the defect that parked them is
 * fixed. Replay is nothing more than clearing the dead-letter mark: the
 * relay re-claims the row on its next pass and the message goes through the
 * exact pipeline it failed in — same validation, same verdicts. Still
 * broken? It dead-letters again, with a fresh error. Replay is safe to
 * repeat for the same reason everything here is: delivery is at-least-once
 * and effects are idempotent.
 */
final class ReplayCommand extends Command
{
    protected $signature = 'outbox:replay
        {--id=* : Dead-lettered message ids to replay}
        {--all : Replay every dead-lettered message}';

    protected $description = 'Return dead-lettered outbox messages to the relay';

    public function handle(): int
    {
        /** @var list<string> $ids */
        $ids = (array) $this->option('id');

        if ($ids === [] && ! (bool) $this->option('all')) {
            $this->error('Name the messages: --id=<n> (repeatable) or --all.');

            return self::INVALID;
        }

        $query = OutboxMessage::query()->whereNotNull('dead_lettered_at');

        if ($ids !== []) {
            $query->whereIn('id', $ids);
        }

        $replayed = $query->update([
            'dead_lettered_at' => null,
            'last_error' => null,
            'available_at' => CarbonImmutable::now(),
        ]);

        $this->info(sprintf('%d message(s) returned to the relay.', $replayed));

        return self::SUCCESS;
    }
}
