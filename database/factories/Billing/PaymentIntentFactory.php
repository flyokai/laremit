<?php

declare(strict_types=1);

namespace Database\Factories\Billing;

use App\Domain\Billing\Enums\PaymentIntentStatus;
use App\Domain\Billing\Models\PaymentIntent;
use App\Domain\Billing\Models\Subscription;
use App\Domain\Catalog\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PaymentIntent>
 */
class PaymentIntentFactory extends Factory
{
    /** @var class-string<PaymentIntent> */
    protected $model = PaymentIntent::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'subscription_id' => Subscription::factory(),
            // Derived from the subscription so user/plan/product agree.
            'user_id' => fn (array $attributes) => Subscription::query()
                ->findOrFail($attributes['subscription_id'])->user_id,
            'plan_id' => fn (array $attributes) => Subscription::query()
                ->findOrFail($attributes['subscription_id'])->plan_id,
            'purpose' => 'initial',
            'amount_minor' => fn (array $attributes) => Plan::query()
                ->findOrFail($attributes['plan_id'])->amount_minor,
            'currency' => fn (array $attributes) => Plan::query()
                ->findOrFail($attributes['plan_id'])->currency,
            'status' => PaymentIntentStatus::Pending,
            'psp_idempotency_key' => (string) Str::ulid(),
        ];
    }

    public function processing(): static
    {
        return $this->state(fn (array $attributes) => ['status' => PaymentIntentStatus::Processing]);
    }
}
