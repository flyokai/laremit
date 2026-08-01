<?php

declare(strict_types=1);

namespace Database\Factories\Catalog;

use App\Domain\Catalog\Enums\BillingInterval;
use App\Domain\Catalog\Models\Plan;
use App\Domain\Catalog\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Plan>
 */
class PlanFactory extends Factory
{
    /** @var class-string<Plan> */
    protected $model = Plan::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'slug' => 'monthly',
            'name' => 'Monthly',
            'amount_minor' => 999,
            'currency' => 'USD',
            'interval' => BillingInterval::Month,
            'interval_count' => 1,
            'trial_days' => 0,
            'active' => true,
        ];
    }

    public function yearly(): static
    {
        return $this->state(fn (array $attributes) => [
            'slug' => 'yearly',
            'name' => 'Yearly',
            'amount_minor' => 9_900,
            'interval' => BillingInterval::Year,
        ]);
    }

    public function withTrial(int $days = 7): static
    {
        return $this->state(fn (array $attributes) => ['trial_days' => $days]);
    }
}
