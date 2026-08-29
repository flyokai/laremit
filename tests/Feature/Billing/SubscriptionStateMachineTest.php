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
    'active -> revoked' => [SubscriptionStatus::Active, SubscriptionStatus::Revoked, true],
    'canceled -> revoked' => [SubscriptionStatus::Canceled, SubscriptionStatus::Revoked, true],
    'revoked is terminal' => [SubscriptionStatus::Revoked, SubscriptionStatus::Active, false],
    'expired -> revoked (nothing to take away)' => [SubscriptionStatus::Expired, SubscriptionStatus::Revoked, false],
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

it('revokes from a live state and stamps revoked_at', function (): void {
    $subscription = Subscription::factory()->canceled()->create();
    $at = CarbonImmutable::now()->startOfSecond();

    stateMachine()->revoke($subscription, $at);

    expect($subscription->status)->toBe(SubscriptionStatus::Revoked)
        ->and($subscription->revoked_at?->equalTo($at))->toBeTrue()
        ->and($subscription->canceled_at)->not->toBeNull();
});

it('mirrors a store snapshot and rejects anything not newer than the watermark', function (): void {
    $subscription = Subscription::factory()->fromStore(App\Domain\Billing\Enums\Store::Apple)->create();
    // The factory's watermark is "now"; the store's next word must be after it.
    $watermark = CarbonImmutable::now()->addSeconds(5)->startOfSecond();

    expect(stateMachine()->mirror($subscription, SubscriptionStatus::Canceled, $watermark))->toBeTrue()
        ->and($subscription->status)->toBe(SubscriptionStatus::Canceled)
        ->and($subscription->last_event_at?->equalTo($watermark))->toBeTrue();

    // Older: stale. Equal: stale. Newer by a millisecond: applied.
    expect(stateMachine()->mirror($subscription, SubscriptionStatus::Active, $watermark->subSecond()))->toBeFalse()
        ->and(stateMachine()->mirror($subscription, SubscriptionStatus::Active, $watermark))->toBeFalse()
        ->and($subscription->refresh()->status)->toBe(SubscriptionStatus::Canceled)
        ->and(stateMachine()->mirror($subscription, SubscriptionStatus::Active, $watermark->addMilliseconds(1)))->toBeTrue()
        ->and($subscription->status)->toBe(SubscriptionStatus::Active)
        ->and($subscription->canceled_at)->toBeNull();
});

it('mirrors transitions outside the allow-list because the store is authoritative', function (): void {
    $subscription = Subscription::factory()->fromStore(App\Domain\Billing\Enums\Store::Apple)->expired()->create();

    expect(stateMachine()->canTransition(SubscriptionStatus::Expired, SubscriptionStatus::Active))->toBeFalse()
        ->and(stateMachine()->mirror($subscription, SubscriptionStatus::Active, CarbonImmutable::now()->addSecond()))->toBeTrue()
        ->and($subscription->status)->toBe(SubscriptionStatus::Active);
});
