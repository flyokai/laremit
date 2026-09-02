<?php

declare(strict_types=1);

namespace App\Domain\Events\Support;

use Closure;

/**
 * Which payload schema versions are live, and how to get from an old one to
 * the current one.
 *
 * Two versions are live at any time so producers upgrade on their own release
 * cadence: ingest accepts both, consumers upcast to current before applying,
 * and the archive stores what was actually received. Replays therefore upcast
 * through whatever chain exists at replay time — upcasters are append-only
 * history, not migrations to delete.
 */
final class SchemaRegistry
{
    /** @var array<string, array<int, Closure(array<string, mixed>): array<string, mixed>>> */
    private array $upcasters = [];

    /**
     * @param  non-empty-list<int>  $liveVersions
     */
    public function __construct(private readonly array $liveVersions) {}

    public function isLive(int $version): bool
    {
        return in_array($version, $this->liveVersions, true);
    }

    public function current(): int
    {
        return max($this->liveVersions);
    }

    /**
     * @return list<int>
     */
    public function live(): array
    {
        return $this->liveVersions;
    }

    /**
     * @param  Closure(array<string, mixed>): array<string, mixed>  $upcaster
     */
    public function registerUpcaster(string $type, int $fromVersion, Closure $upcaster): void
    {
        $this->upcasters[$type][$fromVersion] = $upcaster;
    }

    /**
     * Upcast an envelope's payload as far toward current as registered
     * upcasters allow. A type with no upcaster passes through unchanged —
     * consumers of such types must accept every live shape themselves.
     */
    public function normalize(Envelope $envelope): Envelope
    {
        $version = $envelope->schemaVersion;
        $payload = $envelope->payload;

        while ($version < $this->current()) {
            $upcaster = $this->upcasters[$envelope->type][$version] ?? null;

            if ($upcaster === null) {
                break;
            }

            $payload = $upcaster($payload);
            $version++;
        }

        return $version === $envelope->schemaVersion
            ? $envelope
            : $envelope->withSchema($version, $payload);
    }
}
