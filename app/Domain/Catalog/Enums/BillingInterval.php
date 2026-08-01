<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Enums;

use Carbon\CarbonImmutable;
use DateTimeInterface;

enum BillingInterval: string
{
    case Day = 'day';
    case Week = 'week';
    case Month = 'month';
    case Year = 'year';

    /**
     * Advance a period boundary by $count intervals.
     *
     * Month and year arithmetic deliberately does not overflow: a subscriber
     * billed on 31 January renews on 28 February, not 3 March. Overflowing
     * would silently move every short-month renewal into the following month
     * and, compounded over a year, skip a billing period.
     */
    public function advance(DateTimeInterface $from, int $count = 1): CarbonImmutable
    {
        $date = CarbonImmutable::instance($from);

        return match ($this) {
            self::Day => $date->addDays($count),
            self::Week => $date->addWeeks($count),
            self::Month => $date->addMonthsNoOverflow($count),
            self::Year => $date->addYearsNoOverflow($count),
        };
    }
}
