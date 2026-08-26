<?php

declare(strict_types=1);

namespace App\MockPsp;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Config\Repository as Config;

/**
 * Effective mock-PSP behaviour: config defaults overlaid with runtime
 * overrides kept in cache, so a live chaos run can crank failure rates
 * through the /mock-psp/config endpoint without touching .env or
 * restarting anything.
 */
final readonly class MockPspSettings
{
    private const OVERRIDES_KEY = 'mockpsp:overrides';

    public function __construct(
        private Config $config,
        private Cache $cache,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        /** @var array<string, mixed> $defaults */
        $defaults = (array) $this->config->get('mockpsp');
        /** @var array<string, mixed> $overrides */
        $overrides = (array) $this->cache->get(self::OVERRIDES_KEY, []);

        return array_replace_recursive($defaults, $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    public function override(array $overrides): void
    {
        /** @var array<string, mixed> $current */
        $current = (array) $this->cache->get(self::OVERRIDES_KEY, []);

        $this->cache->put(self::OVERRIDES_KEY, array_replace_recursive($current, $overrides), 3600);
    }

    public function reset(): void
    {
        $this->cache->forget(self::OVERRIDES_KEY);
    }

    public function declinedRate(): float
    {
        return (float) $this->value('outcomes.declined_rate', 0.0);
    }

    public function timeoutRate(): float
    {
        return (float) $this->value('outcomes.timeout_rate', 0.0);
    }

    public function timeoutSleepSeconds(): int
    {
        return (int) $this->value('timeout.sleep_seconds', 6);
    }

    public function webhookUrl(): string
    {
        return (string) $this->value('webhook.url', '');
    }

    public function webhookSecret(): string
    {
        return (string) $this->value('webhook.secret', '');
    }

    /**
     * @return array{int, int}
     */
    public function webhookDelayRange(): array
    {
        /** @var array<int, int|string> $range */
        $range = (array) $this->value('webhook.delay_seconds', [0, 3]);

        return [(int) ($range[0] ?? 0), (int) ($range[1] ?? 3)];
    }

    public function webhookDuplicateRate(): float
    {
        return (float) $this->value('webhook.duplicate_rate', 0.0);
    }

    private function value(string $key, mixed $default): mixed
    {
        $settings = $this->all();

        foreach (explode('.', $key) as $segment) {
            if (! is_array($settings) || ! array_key_exists($segment, $settings)) {
                return $default;
            }

            $settings = $settings[$segment];
        }

        return $settings;
    }
}
