<?php

declare(strict_types=1);

use App\Domain\Events\Enums\Priority;
use App\Domain\Events\Support\Envelope;
use App\Domain\Events\Support\SchemaRegistry;
use Carbon\CarbonImmutable;

function envelopeAt(int $version, array $payload, string $type = 'video.watched'): Envelope
{
    return new Envelope(
        'f47ac10b-58cc-4372-a567-0e02b2c3d479',
        $type,
        $version,
        CarbonImmutable::parse('2026-08-25T10:00:00Z'),
        42,
        'edtech',
        Priority::Analytics,
        $payload,
        CarbonImmutable::parse('2026-08-25T10:00:05Z'),
    );
}

it('knows which versions are live', function (): void {
    $registry = new SchemaRegistry([1, 2]);

    expect($registry->isLive(1))->toBeTrue()
        ->and($registry->isLive(2))->toBeTrue()
        ->and($registry->isLive(3))->toBeFalse()
        ->and($registry->current())->toBe(2);
});

it('upcasts an old payload to the current schema', function (): void {
    $registry = new SchemaRegistry([1, 2]);
    $registry->registerUpcaster('video.watched', 1, fn (array $p): array => [
        'video_id' => $p['video_id'],
        'position_ms' => ((int) $p['position_seconds']) * 1000,
    ]);

    $normalized = $registry->normalize(envelopeAt(1, ['video_id' => 'v1', 'position_seconds' => 30]));

    expect($normalized->schemaVersion)->toBe(2)
        ->and($normalized->payload)->toBe(['video_id' => 'v1', 'position_ms' => 30_000]);
});

it('chains upcasters across multiple versions', function (): void {
    $registry = new SchemaRegistry([1, 2, 3]);
    $registry->registerUpcaster('t.x', 1, fn (array $p): array => $p + ['b' => 1]);
    $registry->registerUpcaster('t.x', 2, fn (array $p): array => $p + ['c' => 2]);

    $normalized = $registry->normalize(envelopeAt(1, ['a' => 0], 't.x'));

    expect($normalized->schemaVersion)->toBe(3)
        ->and($normalized->payload)->toBe(['a' => 0, 'b' => 1, 'c' => 2]);
});

it('returns the envelope untouched when it is already current', function (): void {
    $registry = new SchemaRegistry([1, 2]);
    $envelope = envelopeAt(2, ['position_ms' => 1000]);

    expect($registry->normalize($envelope))->toBe($envelope);
});

it('passes a type with no upcaster through unchanged', function (): void {
    $registry = new SchemaRegistry([1, 2]);
    $envelope = envelopeAt(1, ['legacy' => true], 'unknown.type');

    $normalized = $registry->normalize($envelope);

    expect($normalized->schemaVersion)->toBe(1)
        ->and($normalized->payload)->toBe(['legacy' => true]);
});
