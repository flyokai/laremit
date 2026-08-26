<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use App\Domain\Billing\Console\LedgerCommand;
use App\Domain\Billing\Contracts\PspClient;
use App\Domain\Billing\Psp\HttpPspClient;
use App\MockPsp\LoopbackPspClient;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

/**
 * Wiring for the Billing module. Most services are constructor-injectable
 * with zero config and need no explicit binding; what lives here is the
 * PSP driver switch.
 */
final class BillingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PspClient::class, function (Application $app): PspClient {
            $config = $app->make(Repository::class);
            $driver = (string) $config->get('billing.psp.driver');

            return match ($driver) {
                'http' => new HttpPspClient(
                    $app->make(Http::class),
                    (string) $config->get('billing.psp.base_url'),
                    (float) $config->get('billing.psp.timeout_seconds'),
                ),
                // The mock PSP invoked in-process — the test suite's driver.
                'loopback' => $app->make(LoopbackPspClient::class),
                default => throw new InvalidArgumentException(
                    "Unknown billing.psp.driver [{$driver}]; expected http or loopback."
                ),
            };
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([LedgerCommand::class]);
        }
    }
}
