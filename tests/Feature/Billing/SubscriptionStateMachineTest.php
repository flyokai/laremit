<?php

declare(strict_types=1);

use App\Domain\Billing\Enums\SubscriptionStatus;
use App\Domain\Billing\Exceptions\InvalidTransition;
use App\Domain\Billing\Models\Subscription;
use App\Domain\Billing\Subscriptions\SubscriptionStateMachine;
use Carbon\CarbonImmutable;

function stateMachine(): SubscriptionStateMachine
{
    return app(SubscriptionStateMachine::class);
}

it('answers the allow-list matrix', function (SubscriptionStatus $from, SubscriptionStatus $to, bool $allowed): void {
    expect(stateMachine()->canTransition($from, $to))->toBe($allowed);
})->with([
    'incomplete -> active' => [SubscriptionStatus::Incomplete, SubscriptionStatus::Active, true],
    'incomplete -> past_due' => [SubscriptionStatus::Incomplete, SubscriptionStatus::PastDue, false],
    'active -> canceled' => [SubscriptionStatus::Active, SubscriptionStatus::Canceled, true],
    'active -> incomplete' => [SubscriptionStatus::Active, SubscriptionStatus::Incomplete, false],
    'past_due -> active' => [SubscriptionStatus::PastDue, SubscriptionStatus::Active, true],
    'canceled -> active (resubscribe)' => [SubscriptionStatus::Canceled, SubscriptionStatus::Active, true],
    'canceled -> paused' => [SubscriptionStatus::Canceled, SubscriptionStatus::Paused, false],
    'expired is terminal' => [SubscriptionStatus::Expired, SubscriptionStatus::Active, false],
]);

it('rejects a transition outside the allow-list at write time', function (): void {
    $subscription = Subscription::factory()->expired()->create();

    stateMachine()->transition($subscription, SubscriptionStatus::Active, CarbonImmutable::now());
})->throws(InvalidTransition::class);

it('cancels with a timestamp and keeps the paid period', function (): void {
    $subscription = Subscription::factory()->create();
    $at = CarbonImmutable::now()->startOfSecond();
    $periodEnd = $subscription->current_period_end;

    stateMachine()->transition($subscription, SubscriptionStatus::Canceled, $at);

    expect($subscription->status)->toBe(SubscriptionStatus::Canceled)
        ->and($subscription->canceled_at?->equalTo($at))->toBeTrue()
        ->and($subscription->current_period_end?->equalTo($periodEnd))->toBeTrue()
        ->and($subscription->last_event_at?->equalTo($at))->toBeTrue();
});

it('activates with a fresh period and clears the cancellation', function (): void {
    $subscription = Subscription::factory()->canceled()->create();
    $at = CarbonImmutable::now()->startOfSecond();
    $end = $at->addMonthNoOverflow();

    stateMachine()->activate($subscription, $at, $end, $at);

    expect($subscription->status)->toBe(SubscriptionStatus::Active)
        ->and($subscription->canceled_at)->toBeNull()
        ->and($subscription->current_period_start?->equalTo($at))->toBeTrue()
        ->and($subscription->current_period_end?->equalTo($end))->toBeTrue();
});

it('loses a concurrent race loudly instead of overwriting', function (): void {
    $subscription = Subscription::factory()->create();

    // Another worker moved the row after we loaded it.
    Subscription::query()->whereKey($subscription->id)
        ->update(['status' => SubscriptionStatus::Canceled->value]);

    expect(fn () => stateMachine()->transition($subscription, SubscriptionStatus::PastDue, CarbonImmutable::now()))
        ->toThrow(InvalidTransition::class)
        ->and($subscription->fresh()?->status)->toBe(SubscriptionStatus::Canceled);
});
