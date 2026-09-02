<?php

declare(strict_types=1);

namespace App\Providers;

use App\Http\Context\ActingUser;
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

        // Request state gets a scoped() binding, never singleton(): under
        // Octane, scoped instances are flushed between requests even when
        // resolved at worker boot; a singleton resolved at boot is shared by
        // every request the worker ever serves (ADR-008). The demo flag
        // recreates that bug on purpose — paired with the octane.warm entry
        // in config/octane.php — so the interleaved-user test has something
        // real to catch. It must never be set outside the demo.
        if ((bool) config('octane.demo_cross_request_leak')) {
            $this->app->singleton(ActingUser::class);
        } else {
            $this->app->scoped(ActingUser::class);
        }
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
