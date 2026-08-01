<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Catalog\Enums\BillingInterval;
use App\Domain\Catalog\Models\Plan;
use App\Domain\Catalog\Models\Product;
use Illuminate\Database\Seeder;

/**
 * The three products from the brief, sharing one identity and one billing core.
 *
 * Idempotent: re-running it must not duplicate rows, because it is called on
 * every container start in local development.
 */
class CatalogSeeder extends Seeder
{
    /**
     * @var array<string, array{name: string, plans: list<array{slug: string, name: string, amount_minor: int, interval: BillingInterval, trial_days?: int}>}>
     */
    private const CATALOG = [
        'edtech' => [
            'name' => 'EdTech App',
            'plans' => [
                ['slug' => 'monthly', 'name' => 'Monthly', 'amount_minor' => 1499, 'interval' => BillingInterval::Month, 'trial_days' => 7],
                ['slug' => 'yearly', 'name' => 'Yearly', 'amount_minor' => 11_988, 'interval' => BillingInterval::Year],
            ],
        ],
        'vpn' => [
            'name' => 'VPN Service',
            'plans' => [
                ['slug' => 'monthly', 'name' => 'Monthly', 'amount_minor' => 999, 'interval' => BillingInterval::Month],
                ['slug' => 'yearly', 'name' => 'Yearly', 'amount_minor' => 5_988, 'interval' => BillingInterval::Year],
            ],
        ],
        'ai-tutor' => [
            'name' => 'AI Tutor',
            'plans' => [
                ['slug' => 'monthly', 'name' => 'Monthly', 'amount_minor' => 1999, 'interval' => BillingInterval::Month, 'trial_days' => 14],
            ],
        ],
    ];

    public function run(): void
    {
        foreach (self::CATALOG as $slug => $definition) {
            $product = Product::query()->updateOrCreate(
                ['slug' => $slug],
                ['name' => $definition['name'], 'active' => true],
            );

            foreach ($definition['plans'] as $plan) {
                Plan::query()->updateOrCreate(
                    ['product_id' => $product->id, 'slug' => $plan['slug']],
                    [
                        'name' => $plan['name'],
                        'amount_minor' => $plan['amount_minor'],
                        'currency' => 'USD',
                        'interval' => $plan['interval'],
                        'interval_count' => 1,
                        'trial_days' => $plan['trial_days'] ?? 0,
                        'active' => true,
                    ],
                );
            }
        }
    }
}
