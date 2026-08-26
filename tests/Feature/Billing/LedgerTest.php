<?php

declare(strict_types=1);

use App\Domain\Billing\Enums\LedgerAccount;
use App\Domain\Billing\Enums\LedgerEntryType;
use App\Domain\Billing\Ledger\Ledger;
use App\Domain\Billing\Models\LedgerEntry;
use App\Domain\Billing\Models\PaymentIntent;
use App\Domain\Billing\Money\Money;
use Carbon\CarbonImmutable;

function ledger(): Ledger
{
    return app(Ledger::class);
}

it('books a charge as one balanced two-line transaction', function (): void {
    $intent = PaymentIntent::factory()->create();

    $recorded = ledger()->recordCharge($intent, 'ch_test1', CarbonImmutable::now());

    $entries = LedgerEntry::query()->get();

    expect($recorded)->toBeTrue()
        ->and($entries)->toHaveCount(2)
        ->and($entries->pluck('transaction_id')->unique())->toHaveCount(1)
        ->and((int) $entries->sum('amount_minor'))->toBe(0)
        ->and($entries->firstWhere('account', LedgerAccount::PspCash)?->amount_minor)->toBe($intent->amount_minor)
        ->and($entries->firstWhere('account', LedgerAccount::Revenue)?->amount_minor)->toBe(-$intent->amount_minor);
});

it('records the same charge only once, no matter how often asked', function (): void {
    $intent = PaymentIntent::factory()->create();
    $at = CarbonImmutable::now();

    expect(ledger()->recordCharge($intent, 'ch_dup', $at))->toBeTrue()
        ->and(ledger()->recordCharge($intent, 'ch_dup', $at))->toBeFalse()
        ->and(ledger()->recordCharge($intent, 'ch_dup', $at))->toBeFalse()
        ->and(LedgerEntry::query()->count())->toBe(2);
});

it('refuses an unbalanced transaction before writing anything', function (): void {
    expect(fn () => ledger()->record(
        LedgerEntryType::Adjustment,
        'test',
        '1',
        'unbalanced:1',
        CarbonImmutable::now(),
        [
            [LedgerAccount::PspCash, Money::of(100, 'USD')],
            [LedgerAccount::Revenue, Money::of(-99, 'USD')],
        ],
    ))->toThrow(InvalidArgumentException::class)
        ->and(LedgerEntry::query()->count())->toBe(0);
});

it('refuses a single-line transaction', function (): void {
    ledger()->record(
        LedgerEntryType::Adjustment,
        'test',
        '1',
        'single:1',
        CarbonImmutable::now(),
        [[LedgerAccount::PspCash, Money::of(0, 'USD')]],
    );
})->throws(InvalidArgumentException::class);

it('refuses to record a non-positive charge', function (): void {
    $intent = PaymentIntent::factory()->create();
    PaymentIntent::query()->whereKey($intent->id)->update(['amount_minor' => 0]);

    ledger()->recordCharge($intent->refresh(), 'ch_zero', CarbonImmutable::now());
})->throws(InvalidArgumentException::class);

it('is append-only: updating or deleting an entry throws', function (): void {
    $intent = PaymentIntent::factory()->create();
    ledger()->recordCharge($intent, 'ch_frozen', CarbonImmutable::now());

    $entry = LedgerEntry::query()->firstOrFail();

    // forceFill: mass-assignment protection (nothing is fillable) is the
    // outer wall; this walks past it to prove the model-event wall behind.
    expect(fn () => $entry->forceFill(['amount_minor' => 1])->save())->toThrow(LogicException::class)
        ->and(fn () => $entry->delete())->toThrow(LogicException::class);
});

it('reports balances and a zero-sum trial balance', function (): void {
    $a = PaymentIntent::factory()->create();
    $b = PaymentIntent::factory()->create();

    ledger()->recordCharge($a, 'ch_a', CarbonImmutable::now());
    ledger()->recordCharge($b, 'ch_b', CarbonImmutable::now());

    $expected = $a->amount_minor + $b->amount_minor;
    $balance = ledger()->trialBalance();

    expect(ledger()->balance(LedgerAccount::PspCash, 'USD')->minor)->toBe($expected)
        ->and(ledger()->balance(LedgerAccount::Revenue, 'USD')->minor)->toBe(-$expected)
        ->and($balance['balanced'])->toBeTrue()
        ->and($balance['total_minor'])->toBe(0)
        ->and($balance['entries'])->toBe(4);
});

it('casts the virtual amount attribute to Money on both models', function (): void {
    $intent = PaymentIntent::factory()->create();
    ledger()->recordCharge($intent, 'ch_cast', CarbonImmutable::now());

    $entry = LedgerEntry::query()->where('account', LedgerAccount::PspCash->value)->firstOrFail();

    expect($intent->amount)->toBeInstanceOf(Money::class)
        ->and($intent->amount->minor)->toBe($intent->amount_minor)
        ->and($entry->amount)->toBeInstanceOf(Money::class)
        ->and($entry->amount->equals($intent->amount))->toBeTrue();
});
