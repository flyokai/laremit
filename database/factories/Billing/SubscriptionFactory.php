<?php

declare(strict_types=1);

namespace Database\Factories\Billing;

use App\Domain\Billing\Enums\Store;
use App\Domain\Billing\Enums\SubscriptionStatus;
use App\Domain\Billing\Models\Subscription;
use App\Domain\Catalog\Models\Plan;
use App\Domain\Identity\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Subscription>
 */
class SubscriptionFactory extends Factory
{
    /** @var class-string<Subscription> */
    protected $model = Subscription::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $start = CarbonImmutable::now()->startOfSecond();

        return [
            'user_id' => User::factory(),
            'plan_id' => Plan::factory(),
            // Derived from the plan so the two can never disagree.
            'product_id' => fn (array $attributes) => Plan::query()
                ->findOrFail($attributes['plan_id'])
                ->product_id,
            'status' => SubscriptionStatus::Active,
            'store' => Store::Psp,
            'store_original_transaction_id' => null,
            'trial_ends_at' => null,
            'current_period_start' => $start,
            'current_period_end' => $start->addMonthNoOverflow(),
            'canceled_at' => null,
            'last_event_at' => $start,
        ];
    }

    public function pastDue(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => SubscriptionStatus::PastDue,
        ]);
    }

    public function canceled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => SubscriptionStatus::Canceled,
            'canceled_at' => CarbonImmutable::now(),
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => SubscriptionStatus::Expired,
            'current_period_end' => CarbonImmutable::now()->subDay(),
        ]);
    }

    public function fromStore(Store $store): static
    {
        return $this->state(fn (array $attributes) => [
            'store' => $store,
            'store_original_transaction_id' => (string) fake()->unique()->numerify('###############'),
        ]);
    }
}
