<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use App\Domain\Billing\Console\LedgerCommand;
use App\Domain\Billing\Console\ReconcileCommand;
use App\Domain\Billing\Contracts\PspClient;
use App\Domain\Billing\Psp\HttpPspClient;
use App\Domain\Billing\Reconciliation\Reconciler;
use App\Domain\Billing\Reconciliation\Sweeps\PendingWebhookSweep;
use App\Domain\Billing\Reconciliation\Sweeps\PspChargeSweep;
use App\Domain\Billing\Reconciliation\Sweeps\StoreSubscriptionSweep;
use App\Domain\Billing\Reconciliation\Sweeps\StuckIntentSweep;
use App\Domain\Billing\Stores\Apple\AppleNotificationParser;
use App\Domain\Billing\Stores\HttpStoreClient;
use App\Domain\Billing\Stores\LoopbackStoreClient;
use App\Domain\Billing\Stores\StoreClient;
use App\MockPsp\LoopbackPspClient;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

/**
 * Wiring for the Billing module. Most services are constructor-injectable
 * with zero config and need no explicit binding; what lives here is the
 * provider driver switches (PSP, app stores) and the reconciliation
 * sweep order.
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

        $this->app->singleton(StoreClient::class, function (Application $app): StoreClient {
            $config = $app->make(Repository::class);
            $driver = (string) $config->get('billing.stores.driver');

            return match ($driver) {
                'http' => new HttpStoreClient(
                    $app->make(Http::class),
                    $app->make(AppleNotificationParser::class),
                    (string) $config->get('billing.stores.base_url'),
                    (float) $config->get('billing.stores.timeout_seconds'),
                    (string) $config->get('billing.stores.google.package_name'),
                ),
                'loopback' => $app->make(LoopbackStoreClient::class),
                default => throw new InvalidArgumentException(
                    "Unknown billing.stores.driver [{$driver}]; expected http or loopback."
                ),
            };
        });

        // Sweep order is deliberate: the provider's charge list settles
        // most stuck intents before the stuck-intent sweep would
        // re-dispatch them; store re-sync and the webhook reaper are
        // independent of both.
        $this->app->singleton(Reconciler::class, fn (Application $app): Reconciler => new Reconciler([
            $app->make(PspChargeSweep::class),
            $app->make(StuckIntentSweep::class),
            $app->make(StoreSubscriptionSweep::class),
            $app->make(PendingWebhookSweep::class),
        ]));
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([LedgerCommand::class, ReconcileCommand::class]);
        }
    }
}
