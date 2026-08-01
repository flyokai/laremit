<?php

declare(strict_types=1);

namespace App\Support\Health\Checks;

use App\Support\Health\Check;
use Illuminate\Support\Facades\Redis;
use RuntimeException;

final readonly class RedisCheck implements Check
{
    /**
     * @param  string  $connection  key in config('database.redis')
     * @param  string|null  $requiredEvictionPolicy  assert the instance's maxmemory-policy
     */
    public function __construct(
        private string $connection,
        private ?string $requiredEvictionPolicy = null,
    ) {}

    public function name(): string
    {
        return "redis:{$this->connection}";
    }

    /**
     * @return array<string, scalar>
     */
    public function probe(): array
    {
        $client = Redis::connection($this->connection);

        $client->ping();

        $policy = $this->evictionPolicy();

        // ADR-002 is only worth writing down if something enforces it. A queue
        // or stream instance running an evicting policy silently deletes jobs
        // and events under memory pressure, and the symptom shows up days later
        // as a ledger that does not balance. Catch it at boot instead: this is
        // exactly the misconfiguration you get from pointing two REDIS_*_HOST
        // values at the same container.
        if ($this->requiredEvictionPolicy !== null && $policy !== $this->requiredEvictionPolicy) {
            throw new RuntimeException(sprintf(
                'maxmemory-policy is "%s", expected "%s" (see ADR-002)',
                $policy,
                $this->requiredEvictionPolicy,
            ));
        }

        return ['maxmemory_policy' => $policy];
    }

    private function evictionPolicy(): string
    {
        /** @var mixed $config */
        $config = Redis::connection($this->connection)->config('GET', 'maxmemory-policy');

        // phpredis returns a map, predis a flat [key, value] list.
        if (is_array($config)) {
            if (array_key_exists('maxmemory-policy', $config)) {
                return (string) $config['maxmemory-policy'];
            }

            if (array_is_list($config) && count($config) >= 2) {
                return (string) $config[1];
            }
        }

        throw new RuntimeException('Could not read maxmemory-policy from Redis.');
    }
}
