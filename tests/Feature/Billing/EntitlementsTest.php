<?php

declare(strict_types=1);

use App\Domain\Billing\Entitlements\Entitlements;
use App\Domain\Billing\Enums\SubscriptionStatus;
use App\Domain\Billing\Models\Subscription;
use Carbon\CarbonImmutable;

function entitlements(): Entitlements
{
    return app(Entitlements::class);
}

it('grants and denies access per subscription state', function (SubscriptionStatus $status, bool $expected): void {
    $subscription = Subscription::factory()->create(['status' => $status]);

    expect(entitlements()->hasAccessTo($subscription->user_id, $subscription->product->slug))
        ->toBe($expected);
})->with([
    'active' => [SubscriptionStatus::Active, true],
    'trialing' => [SubscriptionStatus::Trialing, true],
    'past_due (dunning grace)' => [SubscriptionStatus::PastDue, true],
    'paused' => [SubscriptionStatus::Paused, false],
    'incomplete' => [SubscriptionStatus::Incomplete, false],
    'expired' => [SubscriptionStatus::Expired, false],
]);

it('keeps access for a canceled subscription until its paid period ends', function (): void {
    $inPeriod = Subscription::factory()->canceled()->create();
    $lapsed = Subscription::factory()->canceled()->create([
        'current_period_end' => CarbonImmutable::now()->subDay(),
    ]);

    expect(entitlements()->hasAccessTo($inPeriod->user_id, $inPeriod->product->slug))->toBeTrue()
        ->and(entitlements()->hasAccessTo($lapsed->user_id, $lapsed->product->slug))->toBeFalse();
});

it('scopes access to the exact (user, product) pair', function (): void {
    $subscription = Subscription::factory()->create();
    $otherProduct = App\Domain\Catalog\Models\Product::factory()->create();

    expect(entitlements()->hasAccessTo($subscription->user_id, $otherProduct->slug))->toBeFalse()
        ->and(entitlements()->hasAccessTo($subscription->user_id + 999, $subscription->product->slug))->toBeFalse();
});

it('denies access to an unknown product outright', function (): void {
    expect(entitlements()->hasAccessTo(1, 'no-such-product'))->toBeFalse();
});

it('answers over the API with the same function', function (): void {
    $subscription = Subscription::factory()->create();

    $this->getJson(sprintf(
        '/v1/entitlements?user_id=%d&product=%s',
        $subscription->user_id,
        $subscription->product->slug,
    ), ['Authorization' => 'Bearer test-billing-token'])
        ->assertOk()
        ->assertJsonPath('has_access', true);

    $this->getJson('/v1/entitlements?user_id=1&product=vpn')
        ->assertStatus(401);
});
