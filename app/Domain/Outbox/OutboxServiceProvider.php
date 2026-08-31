<?php

declare(strict_types=1);

namespace App\Domain\Outbox;

use App\Domain\Outbox\Console\RelayCommand;
use App\Domain\Outbox\Console\ReplayCommand;
use App\Domain\Outbox\Console\StatusCommand;
use Illuminate\Support\ServiceProvider;

/**
 * Wiring for the Outbox module. Everything is constructor-injectable with
 * zero config — the relay reuses the Events module's Ingestor binding — so
 * all that lives here is the console surface.
 */
final class OutboxServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([RelayCommand::class, StatusCommand::class, ReplayCommand::class]);
        }
    }
}
