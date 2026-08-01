<?php

declare(strict_types=1);

use App\Domain\Billing\Enums\Store;
use App\Domain\Billing\Enums\SubscriptionStatus;

it('grants access only in the states that should', function (SubscriptionStatus $status, bool $expected): void {
    expect($status->grantsAccess())->toBe($expected);
})->with([
    'trialing' => [SubscriptionStatus::Trialing, true],
    'active' => [SubscriptionStatus::Active, true],
    // Deliberate: access survives dunning while the PSP retries the renewal.
    'past due' => [SubscriptionStatus::PastDue, true],
    'incomplete' => [SubscriptionStatus::Incomplete, false],
    'paused' => [SubscriptionStatus::Paused, false],
    // Cancelled-but-still-paid access comes from current_period_end, not here.
    'canceled' => [SubscriptionStatus::Canceled, false],
    'expired' => [SubscriptionStatus::Expired, false],
]);

it('treats only expired as terminal', function (): void {
    expect(SubscriptionStatus::Expired->isTerminal())->toBeTrue()
        ->and(SubscriptionStatus::Canceled->isTerminal())->toBeFalse();
});

it('knows which stores own the subscription lifecycle', function (): void {
    expect(Store::Apple->isStoreAuthoritative())->toBeTrue()
        ->and(Store::Google->isStoreAuthoritative())->toBeTrue()
        ->and(Store::Psp->isStoreAuthoritative())->toBeFalse();
});
