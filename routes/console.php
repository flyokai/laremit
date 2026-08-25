<?php

declare(strict_types=1);

use App\Domain\Events\Models\ArchivedEvent;
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
Schedule::command('model:prune', ['--model' => [ArchivedEvent::class]])->dailyAt('02:00');
