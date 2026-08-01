<?php

declare(strict_types=1);

use App\Domain\Catalog\Enums\BillingInterval;
use Carbon\CarbonImmutable;

it('advances by whole days and weeks', function (): void {
    $from = CarbonImmutable::parse('2026-03-01 10:00:00');

    expect(BillingInterval::Day->advance($from)->toDateString())->toBe('2026-03-02')
        ->and(BillingInterval::Week->advance($from, 2)->toDateString())->toBe('2026-03-15');
});

it('does not overflow a month boundary', function (string $from, string $expected): void {
    expect(BillingInterval::Month->advance(CarbonImmutable::parse($from))->toDateString())
        ->toBe($expected);
})->with([
    // A subscriber billed on the 31st must renew on the last day of a short
    // month, not skip forward into the month after it.
    'jan 31 -> feb 28' => ['2026-01-31', '2026-02-28'],
    'jan 31 in a leap year -> feb 29' => ['2028-01-31', '2028-02-29'],
    'mar 31 -> apr 30' => ['2026-03-31', '2026-04-30'],
    'ordinary month' => ['2026-06-15', '2026-07-15'],
]);

it('does not overflow 29 february when advancing a year', function (): void {
    expect(BillingInterval::Year->advance(CarbonImmutable::parse('2028-02-29'))->toDateString())
        ->toBe('2029-02-28');
});

it('preserves the time of day', function (): void {
    expect(BillingInterval::Month->advance(CarbonImmutable::parse('2026-01-31 03:04:05'))->toTimeString())
        ->toBe('03:04:05');
});

it('returns an immutable date and leaves the input untouched', function (): void {
    $from = CarbonImmutable::parse('2026-01-01');
    $advanced = BillingInterval::Month->advance($from);

    expect($advanced)->toBeInstanceOf(CarbonImmutable::class)
        ->and($from->toDateString())->toBe('2026-01-01');
});
