<?php

declare(strict_types=1);

namespace App\Providers;

use App\Support\Health\Checks\DatabaseCheck;
use App\Support\Health\Checks\RedisCheck;
use App\Support\Health\HealthChecker;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(HealthChecker::class, static fn (): HealthChecker => new HealthChecker([
            new DatabaseCheck,
            new RedisCheck('cache'),
            // The eviction policy is asserted, not just observed — see RedisCheck.
            new RedisCheck('queue', 'noeviction'),
            new RedisCheck('stream', 'noeviction'),
        ]));
    }

    public function boot(): void
    {
        // Every date in this application is immutable. Billing code passes
        // period boundaries between objects constantly, and a mutable Carbon
        // that one caller happens to ->addMonth() in place is a class of bug
        // that simply cannot be allowed near a renewal date.
        Date::use(CarbonImmutable::class);

        // Lazy loading, missing attributes and silently discarded mass
        // assignment all become exceptions outside production. Each of them is
        // a correctness bug here, not a style issue.
        Model::shouldBeStrict(! $this->app->isProduction());

        DB::prohibitDestructiveCommands($this->app->isProduction());
    }
}
