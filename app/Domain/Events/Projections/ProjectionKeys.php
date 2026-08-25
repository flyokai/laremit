<?php

declare(strict_types=1);

namespace App\Domain\Events\Projections;

use Carbon\CarbonImmutable;

/**
 * One place for projection key shapes, shared by the writer (consumer) and
 * every reader, so the two can never drift apart.
 */
final class ProjectionKeys
{
    /** HyperLogLog of distinct user ids per product per UTC day. */
    public static function dau(string $product, CarbonImmutable $day): string
    {
        return sprintf('events:proj:dau:%s:%s', $product, $day->format('Ymd'));
    }

    /** Bitmap of active user ids per UTC day, offset = user id. */
    public static function active(CarbonImmutable $day): string
    {
        return sprintf('events:proj:active:%s', $day->format('Ymd'));
    }

    /**
     * Absolute expiry: end of the projected day plus retention. Absolute, not
     * sliding, so re-applying an event on redelivery cannot extend a key's
     * life — expiry stays idempotent along with the write itself.
     */
    public static function expiresAt(CarbonImmutable $day, int $retentionDays): int
    {
        return $day->utc()->endOfDay()->addDays($retentionDays)->getTimestamp();
    }
}
