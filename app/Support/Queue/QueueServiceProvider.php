<?php

declare(strict_types=1);

namespace App\Support\Queue;

use App\Support\Queue\Console\FloodCommand;
use Illuminate\Queue\Events\QueueBusy;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Laravel\Horizon\Events\LongWaitDetected;

/**
 * Queue observability wiring (Phase 6, ADR-007). Two watchers, two
 * different questions:
 *
 *  - LongWaitDetected (Horizon, thresholds in horizon.waits) answers "is
 *    this lane falling behind?" — wait time is the symptom the caller
 *    feels, and the primary alert.
 *  - QueueBusy (queue:monitor, scheduled in routes/console.php) answers
 *    "is something flooding a lane?" — depth is a cause, not a symptom,
 *    but it fires while wait time is still fine, and it is the only one
 *    of the two that works with Horizon itself down.
 *
 * Both land as Log::critical, the same posture as every other alarm in
 * this codebase (tech-debt #19): the metric exists before the pager does,
 * and Phase 9 turns these into pages.
 */
final class QueueServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Event::listen(static function (LongWaitDetected $event): void {
            Log::critical('Queue wait time is over its threshold; this lane is falling behind.', [
                'connection' => $event->connection,
                'queue' => $event->queue,
                'wait_seconds' => $event->seconds,
            ]);
        });

        Event::listen(static function (QueueBusy $event): void {
            Log::critical('Queue depth is over its threshold; something is flooding this lane.', [
                'connection' => $event->connectionName,
                'queue' => $event->queue,
                'depth' => $event->size,
            ]);
        });

        if ($this->app->runningInConsole()) {
            $this->commands([FloodCommand::class]);
        }
    }
}
