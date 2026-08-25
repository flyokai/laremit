<?php

declare(strict_types=1);

use App\Domain\Events\Enums\Priority;
use App\Domain\Events\Support\EnvelopeValidator;
use App\Domain\Events\Support\SchemaRegistry;
use Carbon\CarbonImmutable;

function envelopeValidator(): EnvelopeValidator
{
    return new EnvelopeValidator(new SchemaRegistry([1, 2]));
}

/** @return array<string, mixed> */
function validEvent(): array
{
    return [
        'event_id' => 'f47ac10b-58cc-4372-a567-0e02b2c3d479',
        'type' => 'video.watched',
        'schema_version' => 2,
        'occurred_at' => '2026-08-25T10:00:00Z',
        'user_id' => 42,
        'product' => 'edtech',
        'priority' => 'analytics',
        'payload' => ['video_id' => 'v1', 'position_ms' => 1000],
    ];
}

const RECEIVED_AT = '2026-08-25 10:00:05';

it('accepts a fully specified envelope', function (): void {
    expect(envelopeValidator()->errors(validEvent(), CarbonImmutable::parse(RECEIVED_AT)))->toBe([]);
});

it('accepts an envelope with only the required fields', function (): void {
    $event = validEvent();
    unset($event['user_id'], $event['priority'], $event['payload']);

    expect(envelopeValidator()->errors($event, CarbonImmutable::parse(RECEIVED_AT)))->toBe([]);
});

it('rejects a non-object event wholesale', function (): void {
    expect(envelopeValidator()->errors('not-an-object', CarbonImmutable::parse(RECEIVED_AT)))
        ->toHaveKey('event');
});

it('rejects field violations and names each field', function (array $overrides, string $field): void {
    $errors = envelopeValidator()->errors(array_merge(validEvent(), $overrides), CarbonImmutable::parse(RECEIVED_AT));

    expect($errors)->toHaveKey($field);
})->with([
    'missing event_id' => [['event_id' => null], 'event_id'],
    'malformed uuid' => [['event_id' => 'not-a-uuid'], 'event_id'],
    'uppercase type' => [['type' => 'Video.Watched'], 'type'],
    'empty type' => [['type' => ''], 'type'],
    'schema_version as string' => [['schema_version' => '2'], 'schema_version'],
    'schema_version not live' => [['schema_version' => 3], 'schema_version'],
    'product with slash' => [['product' => 'ed/tech'], 'product'],
    'occurred_at relative' => [['occurred_at' => 'tomorrow'], 'occurred_at'],
    'occurred_at gibberish' => [['occurred_at' => '2026-99-99T99:00:00Z'], 'occurred_at'],
    'occurred_at implausibly old' => [['occurred_at' => '1970-01-01T00:00:01Z'], 'occurred_at'],
    'occurred_at too far in the future' => [['occurred_at' => '2026-08-25T12:00:00Z'], 'occurred_at'],
    'user_id zero' => [['user_id' => 0], 'user_id'],
    'user_id as string' => [['user_id' => '42'], 'user_id'],
    'payload as scalar' => [['payload' => 'oops'], 'payload'],
    'unknown priority' => [['priority' => 'urgent'], 'priority'],
]);

it('tolerates forward clock skew up to an hour', function (): void {
    $event = array_merge(validEvent(), ['occurred_at' => '2026-08-25T10:59:00Z']);

    expect(envelopeValidator()->errors($event, CarbonImmutable::parse(RECEIVED_AT)))->toBe([]);
});

it('builds an envelope with defaults applied and the event id lowercased', function (): void {
    $event = validEvent();
    $event['event_id'] = strtoupper($event['event_id']);
    unset($event['priority'], $event['user_id'], $event['payload']);

    $receivedAt = CarbonImmutable::parse(RECEIVED_AT);
    $envelope = envelopeValidator()->toEnvelope($event, $receivedAt);

    expect($envelope->eventId)->toBe('f47ac10b-58cc-4372-a567-0e02b2c3d479')
        ->and($envelope->priority)->toBe(Priority::Analytics)
        ->and($envelope->userId)->toBeNull()
        ->and($envelope->payload)->toBe([])
        ->and($envelope->receivedAt)->toBe($receivedAt)
        ->and($envelope->occurredAt->toISOString())->toBe('2026-08-25T10:00:00.000000Z');
});

it('round-trips an envelope through the wire format', function (): void {
    $envelope = envelopeValidator()->toEnvelope(validEvent(), CarbonImmutable::parse(RECEIVED_AT));

    $restored = App\Domain\Events\Support\Envelope::fromJson($envelope->toJson());

    expect($restored->eventId)->toBe($envelope->eventId)
        ->and($restored->type)->toBe($envelope->type)
        ->and($restored->schemaVersion)->toBe($envelope->schemaVersion)
        ->and($restored->userId)->toBe($envelope->userId)
        ->and($restored->product)->toBe($envelope->product)
        ->and($restored->priority)->toBe($envelope->priority)
        ->and($restored->payload)->toBe($envelope->payload)
        ->and($restored->occurredAt->equalTo($envelope->occurredAt))->toBeTrue()
        ->and($restored->receivedAt->equalTo($envelope->receivedAt))->toBeTrue();
});

it('refuses to rehydrate a malformed stream entry', function (string $json): void {
    App\Domain\Events\Support\Envelope::fromJson($json);
})->throws(InvalidArgumentException::class)->with([
    'not json' => ['{nope'],
    'missing fields' => ['{"event_id":"x"}'],
    'wrong types' => ['{"event_id":"x","type":"t","product":"p","occurred_at":"2026-01-01T00:00:00Z","received_at":"2026-01-01T00:00:00Z","priority":"analytics","schema_version":"2"}'],
    'unknown priority' => ['{"event_id":"x","type":"t","product":"p","occurred_at":"2026-01-01T00:00:00Z","received_at":"2026-01-01T00:00:00Z","priority":"asap","schema_version":2}'],
]);
