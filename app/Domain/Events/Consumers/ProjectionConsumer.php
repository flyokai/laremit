<?php

declare(strict_types=1);

namespace App\Domain\Events\Consumers;

use App\Domain\Events\Contracts\Consumer;
use App\Domain\Events\Projections\ProjectionKeys;
use App\Domain\Events\Support\PhpRedis;
use Illuminate\Contracts\Redis\Factory;
use Illuminate\Support\Facades\Log;

/**
 * Real-time DAU projections: a HyperLogLog per (product, day) and an
 * active-user bitmap per day.
 *
 * Idempotency is structural — PFADD and SETBIT are commutative and
 * re-applicable, so redelivery after a crash simply repeats writes that
 * change nothing. This is why these projections need no applied-marker
 * bookkeeping at all.
 *
 * Lives on the cache instance (evictable, ADR-002): every key here can be
 * rebuilt by replaying the archive, so losing one is a recompute, not a loss.
 */
final class ProjectionConsumer implements Consumer
{
    /** SETBIT's hard ceiling is a 512MB value: offsets up to 2^32 - 1. */
    private const MAX_BITMAP_OFFSET = 4_294_967_295;

    private bool $warnedAboutOffset = false;

    public function __construct(
        private readonly Factory $redis,
        private readonly string $connection,
        private readonly int $retentionDays,
    ) {}

    public function apply(array $envelopes): void
    {
        if ($envelopes === []) {
            return;
        }

        PhpRedis::pipeline(PhpRedis::connection($this->redis, $this->connection), function ($pipe) use ($envelopes): void {
            foreach ($envelopes as $envelope) {
                if ($envelope->userId === null) {
                    continue;
                }

                $day = $envelope->occurredAt->startOfDay();
                $expiresAt = ProjectionKeys::expiresAt($day, $this->retentionDays);

                $dau = ProjectionKeys::dau($envelope->product, $day);
                $pipe->pfadd($dau, [$envelope->userId]);
                $pipe->expireat($dau, $expiresAt);

                if ($envelope->userId <= self::MAX_BITMAP_OFFSET) {
                    $active = ProjectionKeys::active($day);
                    // true, not 1: phpredis declares the bit value as bool and
                    // this file runs under strict_types.
                    $pipe->setbit($active, $envelope->userId, true);
                    $pipe->expireat($active, $expiresAt);
                } elseif (! $this->warnedAboutOffset) {
                    $this->warnedAboutOffset = true;
                    Log::warning('User id exceeds bitmap offset ceiling; skipping activity bitmap.', [
                        'user_id' => $envelope->userId,
                    ]);
                }
            }
        });
    }
}
