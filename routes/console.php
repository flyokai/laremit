<?php

declare(strict_types=1);

use App\Domain\Billing\Models\WebhookEvent;
use App\Domain\Events\Models\ArchivedEvent;
use App\Domain\Outbox\Models\DomainEventConsumption;
use App\Domain\Outbox\Models\OutboxMessage;
use App\Support\Idempotency\IdempotencyRecord;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function (): void {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Archive retention (Phase 2). Partition rotation first — on MySQL it does
// the heavy deletion as metadata drops — then model:prune sweeps whatever a
// non-partitioned driver (or a missed rotation) left behind.
Schedule::command('events:partitions', ['--force'])->dailyAt('01:00');
Schedule::command('model:prune', ['--model' => [ArchivedEvent::class, IdempotencyRecord::class, WebhookEvent::class, OutboxMessage::class, DomainEventConsumption::class]])->dailyAt('02:00');

// A missing or stalled consumer group is silent otherwise: XADD MAXLEN trims
// by aggregate stream length, oblivious to any one group's progress. Cadence
// is a tradeoff — frequent enough to catch a dead worker before its backlog
// ages past the buffer's retention, cheap enough not to matter running idle.
Schedule::command('events:check-lag')->everyFiveMinutes();

// Webhooks are an optimization; this is the source of truth (Phase 4). The
// window (26h) overlaps the cadence (1h) by design — a skipped or slow run
// cannot open a gap. withoutOverlapping + onOneServer: two concurrent runs
// would each re-dispatch the same stuck charges, harmlessly but noisily.
Schedule::command('billing:reconcile')->hourly()->withoutOverlapping()->onOneServer();

// A dead outbox relay is silent by construction — writers keep committing
// rows, and nothing downstream misses events it never heard about. Watch the
// backlog's age (the symptom), not the relay process (the cause) — same
// posture as events:check-lag.
Schedule::command('outbox:status', ['--check'])->everyFiveMinutes();
