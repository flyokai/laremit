<?php

declare(strict_types=1);

namespace App\MockStores;

use Carbon\CarbonImmutable;

/**
 * The mock stores' clock: UTC, millisecond precision like the real ones,
 * and strictly increasing within a process. Two changes to one record
 * can never share a timestamp, so the ordering guard on the app side is
 * exercised by every test rather than accidentally satisfied by a fast
 * machine producing equal signedDates.
 */
final class StoreClock
{
    private static ?CarbonImmutable $last = null;

    public static function now(): CarbonImmutable
    {
        $now = CarbonImmutable::now('UTC');
        $now = $now->setMicrosecond(intdiv($now->microsecond, 1000) * 1000);

        if (self::$last !== null && ! $now->isAfter(self::$last)) {
            $now = self::$last->addMilliseconds(1);
        }

        return self::$last = $now;
    }
}
