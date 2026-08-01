<?php

declare(strict_types=1);

use App\Domain\Billing\Enums\Store;
use App\Domain\Billing\Enums\SubscriptionStatus;
use App\Domain\Billing\Models\Subscription;
use App\Domain\Identity\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;

it('casts status, store and every timestamp', function (): void {
    $subscription = Subscription::factory()->create()->fresh();

    expect($subscription->status)->toBe(SubscriptionStatus::Active)
        ->and($subscription->store)->toBe(Store::Psp)
        ->and($subscription->current_period_end)->toBeInstanceOf(CarbonImmutable::class);
});

it('keeps the plan and the product in agreement', function (): void {
    $subscription = Subscription::factory()->create();

    expect($subscription->product_id)->toBe($subscription->plan->product_id);
});

it('hangs off one shared identity across products', function (): void {
    $user = User::factory()->create();

    Subscription::factory()->count(3)->for($user)->create();

    expect($user->subscriptions()->count())->toBe(3);
});

it('reports whether the paid period is still running', function (): void {
    $active = Subscription::factory()->create();
    $lapsed = Subscription::factory()->expired()->create();

    expect($active->withinCurrentPeriod())->toBeTrue()
        ->and($lapsed->withinCurrentPeriod())->toBeFalse();
});

it('lets the database reject a replayed store transaction', function (): void {
    $first = Subscription::factory()->fromStore(Store::Apple)->create();

    // The same original_transaction_id arriving twice from Apple is the exact
    // duplicate-webhook case Phase 4 has to survive. The unique index is what
    // makes that survivable without a read-then-write race.
    expect(fn () => Subscription::factory()->create([
        'store' => Store::Apple,
        'store_original_transaction_id' => $first->store_original_transaction_id,
    ]))->toThrow(QueryException::class);
});

it('does not constrain psp subscriptions that have no store transaction id', function (): void {
    Subscription::factory()->count(2)->create([
        'store' => Store::Psp,
        'store_original_transaction_id' => null,
    ]);

    expect(Subscription::query()->count())->toBe(2);
});
