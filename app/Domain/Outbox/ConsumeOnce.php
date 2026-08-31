<?php

declare(strict_types=1);

namespace App\Domain\Outbox;

use App\Domain\Outbox\Models\DomainEventConsumption;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * At-least-once delivery turned into an exactly-once EFFECT, for effects that
 * are not naturally idempotent (counters, anything read-modify-write).
 *
 * The trick is one transaction around two writes: insert the consumption
 * marker (INSERT IGNORE against UNIQUE(consumer, event_id)), and if — only
 * if — the marker was fresh, apply the effect. Crash before commit: neither
 * exists, redelivery starts clean. Crash after: both exist, redelivery hits
 * the marker and does nothing. There is no interleaving where the effect
 * applies twice, and none where the marker lies about work not done.
 *
 * Concurrent deliveries serialize on the marker row's unique key: the loser
 * of the insert race blocks until the winner commits, then reads 0 rows
 * inserted and walks away.
 */
final readonly class ConsumeOnce
{
    /**
     * Returns true when the effect ran, false when the event was already
     * consumed by this consumer.
     */
    public static function apply(string $consumer, string $eventId, Closure $effect): bool
    {
        return DB::transaction(function () use ($consumer, $eventId, $effect): bool {
            $inserted = DomainEventConsumption::query()->insertOrIgnore([
                'consumer' => $consumer,
                'event_id' => $eventId,
                'processed_at' => CarbonImmutable::now()->format('Y-m-d H:i:s'),
            ]);

            if ($inserted === 0) {
                return false;
            }

            $effect();

            return true;
        });
    }
}
