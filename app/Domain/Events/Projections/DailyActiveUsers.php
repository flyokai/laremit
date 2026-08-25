<?php

declare(strict_types=1);

namespace App\Domain\Events\Projections;

use App\Domain\Events\Support\PhpRedis;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Redis\Factory;

/**
 * Read side of the DAU projections. Counts are approximate by construction —
 * HyperLogLog has ~0.81% standard error — which is the trade that lets a
 * day of distinct users cost 12KB instead of a set of every id.
 */
final readonly class DailyActiveUsers
{
    public function __construct(
        private Factory $redis,
        private string $connection,
    ) {}

    public function count(string $product, CarbonImmutable $day): int
    {
        return (int) PhpRedis::connection($this->redis, $this->connection)
            ->pfcount(ProjectionKeys::dau($product, $day->startOfDay()));
    }

    public function activeCount(CarbonImmutable $day): int
    {
        return (int) PhpRedis::connection($this->redis, $this->connection)
            ->bitcount(ProjectionKeys::active($day->startOfDay()));
    }

    public function wasActive(int $userId, CarbonImmutable $day): bool
    {
        return (int) PhpRedis::connection($this->redis, $this->connection)
            ->getbit(ProjectionKeys::active($day->startOfDay()), $userId) === 1;
    }
}
