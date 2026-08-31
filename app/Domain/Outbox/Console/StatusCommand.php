<?php

declare(strict_types=1);

namespace App\Domain\Outbox\Console;

use App\Domain\Outbox\Models\OutboxMessage;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * The outbox's vital signs, and the watchdog behind them. A dead relay is
 * silent by construction — writers keep committing rows and nothing
 * downstream misses events it never heard about — so the alarm watches the
 * backlog's AGE, not the relay process: symptoms, not causes, same posture
 * as events:check-lag.
 */
final class StatusCommand extends Command
{
    protected $signature = 'outbox:status
        {--check : Exit non-zero and log critically when the backlog is stale or messages are dead-lettered}';

    protected $description = 'Show outbox backlog, dispatch and dead-letter counts';

    public function handle(): int
    {
        $now = CarbonImmutable::now();

        $pending = OutboxMessage::query()
            ->whereNull('dispatched_at')
            ->whereNull('dead_lettered_at')
            ->where('available_at', '<=', $now);

        $backlog = (int) $pending->count();
        $oldestAvailableAt = $pending->min('available_at');
        $oldestAgeSeconds = is_string($oldestAvailableAt)
            ? max(0, $now->getTimestamp() - CarbonImmutable::parse($oldestAvailableAt)->getTimestamp())
            : 0;

        $dead = (int) OutboxMessage::query()->whereNotNull('dead_lettered_at')->count();
        $dispatchedLastDay = (int) OutboxMessage::query()->where('dispatched_at', '>=', $now->subDay())->count();

        $this->table(['metric', 'value'], [
            ['pending', $backlog],
            ['oldest pending age (s)', $oldestAgeSeconds],
            ['dead-lettered', $dead],
            ['dispatched (24h)', $dispatchedLastDay],
        ]);

        if (! (bool) $this->option('check')) {
            return self::SUCCESS;
        }

        $maxAge = (int) config('outbox.alarm.max_backlog_age_seconds');
        $stale = $oldestAgeSeconds > $maxAge;

        if (! $stale && $dead === 0) {
            return self::SUCCESS;
        }

        if ($stale) {
            $this->error(sprintf('Oldest pending outbox message is %ds old (max %ds) — is the relay running?', $oldestAgeSeconds, $maxAge));
        }

        if ($dead > 0) {
            $this->error(sprintf('%d dead-lettered outbox messages await outbox:replay.', $dead));
        }

        Log::critical('Outbox delivery is unhealthy.', [
            'backlog' => $backlog,
            'oldest_age_seconds' => $oldestAgeSeconds,
            'max_age_seconds' => $maxAge,
            'dead_lettered' => $dead,
        ]);

        return self::FAILURE;
    }
}
