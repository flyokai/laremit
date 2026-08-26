<?php

declare(strict_types=1);

use App\Domain\Billing\Money\Money;

it('holds integer minor units and a currency', function (): void {
    $money = Money::of(999, 'USD');

    expect($money->minor)->toBe(999)
        ->and($money->currency)->toBe('USD')
        ->and((string) $money)->toBe('USD 999');
});

it('rejects anything that is not an uppercase ISO code', function (string $currency): void {
    Money::of(100, $currency);
})->throws(InvalidArgumentException::class)->with(['usd', 'US', 'USDA', 'US1', '']);

it('does exact integer arithmetic', function (): void {
    $a = Money::of(1000, 'USD');
    $b = Money::of(999, 'USD');

    expect($a->add($b)->minor)->toBe(1999)
        ->and($a->subtract($b)->minor)->toBe(1)
        ->and($b->negate()->minor)->toBe(-999)
        ->and($a->equals(Money::of(1000, 'USD')))->toBeTrue()
        ->and($a->equals(Money::of(1000, 'EUR')))->toBeFalse();
});

it('refuses mixed-currency arithmetic instead of converting', function (): void {
    Money::of(100, 'USD')->add(Money::of(100, 'EUR'));
})->throws(InvalidArgumentException::class);

it('knows its sign', function (): void {
    expect(Money::of(1, 'USD')->isPositive())->toBeTrue()
        ->and(Money::of(0, 'USD')->isZero())->toBeTrue()
        ->and(Money::of(-1, 'USD')->isNegative())->toBeTrue();
});

it('is immutable: arithmetic returns new instances', function (): void {
    $original = Money::of(500, 'USD');
    $original->add(Money::of(1, 'USD'));

    expect($original->minor)->toBe(500);
});

it('knows the transition allow-list for payment intents', function (): void {
    $pending = App\Domain\Billing\Enums\PaymentIntentStatus::Pending;
    $processing = App\Domain\Billing\Enums\PaymentIntentStatus::Processing;
    $succeeded = App\Domain\Billing\Enums\PaymentIntentStatus::Succeeded;
    $failed = App\Domain\Billing\Enums\PaymentIntentStatus::Failed;

    // Pending may settle directly: webhooks can outrun the ChargeJob.
    expect($pending->canTransitionTo($processing))->toBeTrue()
        ->and($pending->canTransitionTo($succeeded))->toBeTrue()
        ->and($processing->canTransitionTo($failed))->toBeTrue()
        ->and($succeeded->canTransitionTo($failed))->toBeFalse()
        ->and($failed->canTransitionTo($processing))->toBeFalse()
        ->and($succeeded->isTerminal())->toBeTrue()
        ->and($processing->isTerminal())->toBeFalse();
});
