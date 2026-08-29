<?php

declare(strict_types=1);

namespace App\MockStores;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Config\Repository as Config;

/**
 * Effective mock-store behaviour: config defaults overlaid with runtime
 * overrides kept in cache, exactly like MockPspSettings — so a chaos run
 * can turn the drop rate up through /mock-stores/config and back down
 * without a restart.
 */
final readonly class MockStoresSettings
{
    private const OVERRIDES_KEY = 'mockstores:overrides';

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
        $defaults = (array) $this->config->get('mockstores');
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

    public function environment(): string
    {
        return (string) $this->value('environment', 'Sandbox');
    }

    public function appleSigningKeyPem(): ?string
    {
        $encoded = $this->value('apple.signing_key', null);

        if (! is_string($encoded) || $encoded === '') {
            return null;
        }

        $pem = base64_decode($encoded, true);

        return is_string($pem) && str_contains($pem, 'PRIVATE KEY') ? $pem : null;
    }

    public function appleBundleId(): string
    {
        return (string) $this->value('apple.bundle_id', 'com.laremit.app');
    }

    public function appleNotificationUrl(): string
    {
        return (string) $this->value('apple.notification_url', '');
    }

    public function googlePackageName(): string
    {
        return (string) $this->value('google.package_name', 'com.laremit.app');
    }

    public function googleNotificationUrl(): string
    {
        return (string) $this->value('google.notification_url', '');
    }

    public function googlePubsubToken(): string
    {
        return (string) $this->value('google.pubsub_token', '');
    }

    /**
     * @return array{int, int}
     */
    public function delayRange(): array
    {
        /** @var array<int, int|string> $range */
        $range = (array) $this->value('delivery.delay_seconds', [0, 3]);

        return [(int) ($range[0] ?? 0), (int) ($range[1] ?? 3)];
    }

    public function duplicateRate(): float
    {
        return (float) $this->value('delivery.duplicate_rate', 0.0);
    }

    public function dropRate(): float
    {
        return (float) $this->value('delivery.drop_rate', 0.0);
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
