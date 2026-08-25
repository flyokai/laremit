<?php

declare(strict_types=1);

namespace App\Domain\Events\Support;

use Illuminate\Contracts\Redis\Factory;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Redis;
use RuntimeException;
use Throwable;

/**
 * The Events module requires phpredis connections, not just any Redis
 * client: the hot path pipelines native stream commands (XADD, XREADGROUP,
 * XAUTOCLAIM) whose calling conventions and pipeline semantics differ
 * between phpredis and predis. Fail loudly at first use rather than half-work
 * against the wrong client.
 */
final class PhpRedis
{
    public static function connection(Factory $redis, string $name): PhpRedisConnection
    {
        $connection = $redis->connection($name);

        if (! $connection instanceof PhpRedisConnection) {
            throw new RuntimeException(sprintf(
                'Redis connection [%s] is %s; the event pipeline requires phpredis (REDIS_CLIENT=phpredis).',
                $name,
                $connection::class,
            ));
        }

        return $connection;
    }

    /**
     * Pipeline with poison-proofing. If the callback throws mid-build, the
     * shared phpredis client is left in pipeline mode and every later command
     * on this connection would queue silently and return the client object —
     * a broken worker that looks alive. Discard the half-built pipeline
     * before letting the exception continue.
     *
     * @param  callable(Redis): void  $callback
     * @return array<int, mixed>
     */
    public static function pipeline(PhpRedisConnection $connection, callable $callback): array
    {
        try {
            return (array) $connection->pipeline($callback);
        } catch (Throwable $e) {
            try {
                $connection->client()->discard();
            } catch (Throwable) {
                // Not in pipeline mode after all; nothing to discard.
            }

            throw $e;
        }
    }
}
