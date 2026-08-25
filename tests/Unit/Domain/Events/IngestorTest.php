<?php

declare(strict_types=1);

use App\Domain\Events\Enums\EventStatus;
use App\Domain\Events\Ingestion\Ingestor;
use App\Domain\Events\Support\EnvelopeValidator;
use App\Domain\Events\Support\SchemaRegistry;
use Illuminate\Support\Str;
use Tests\Support\FakeEventBuffer;

function ingestor(FakeEventBuffer $buffer, int $shedAbove = 100, int $rejectAbove = 200): Ingestor
{
    return new Ingestor(
        $buffer,
        new EnvelopeValidator(new SchemaRegistry([1, 2])),
        $shedAbove,
        $rejectAbove,
        retryAfterSeconds: 10,
    );
}

/** @return array<string, mixed> */
function rawEvent(array $overrides = []): array
{
    return array_merge([
        'event_id' => (string) Str::uuid(),
        'type' => 'video.watched',
        'schema_version' => 2,
        'occurred_at' => now()->toISOString(),
        'user_id' => 42,
        'product' => 'edtech',
        'priority' => 'analytics',
        'payload' => ['position_ms' => 1000],
    ], $overrides);
}

it('accepts valid events and appends them to the buffer', function (): void {
    $buffer = new FakeEventBuffer;

    $result = ingestor($buffer)->ingest([rawEvent(), rawEvent()]);

    expect($result->accepted)->toBe(2)
        ->and($buffer->appended)->toHaveCount(2)
        ->and($result->rows[0]['status'])->toBe(EventStatus::Accepted);
});

it('reports invalid events per index without appending them', function (): void {
    $buffer = new FakeEventBuffer;

    $result = ingestor($buffer)->ingest([
        rawEvent(),
        rawEvent(['event_id' => 'broken']),
        rawEvent(),
    ]);

    expect($result->accepted)->toBe(2)
        ->and($result->invalid)->toBe(1)
        ->and($result->rows[1]['status'])->toBe(EventStatus::Invalid)
        ->and($result->rows[1]['errors'] ?? [])->toHaveKey('event_id')
        ->and($buffer->appended)->toHaveCount(2);
});

it('marks an event_id already in the dedup window as duplicate', function (): void {
    $buffer = new FakeEventBuffer;
    $event = rawEvent();
    $buffer->seen[strtolower($event['event_id'])] = true;

    $result = ingestor($buffer)->ingest([$event]);

    expect($result->duplicates)->toBe(1)
        ->and($result->accepted)->toBe(0)
        ->and($buffer->appended)->toBeEmpty();
});

it('marks the second occurrence within one batch as duplicate', function (): void {
    $buffer = new FakeEventBuffer;
    $event = rawEvent();

    $result = ingestor($buffer)->ingest([$event, $event]);

    expect($result->accepted)->toBe(1)
        ->and($result->duplicates)->toBe(1)
        ->and($buffer->appended)->toHaveCount(1);
});

it('sheds analytics but not operational events above the shed watermark', function (): void {
    $buffer = new FakeEventBuffer;
    $buffer->depth = 150; // between shed (100) and reject (200)

    $result = ingestor($buffer)->ingest([
        rawEvent(['priority' => 'analytics']),
        rawEvent(['priority' => 'operational']),
    ]);

    expect($result->shed)->toBe(1)
        ->and($result->accepted)->toBe(1)
        ->and($result->rows[0]['status'])->toBe(EventStatus::Shed)
        ->and($result->rows[1]['status'])->toBe(EventStatus::Accepted)
        ->and($buffer->appended)->toHaveCount(1);
});

it('rejects the whole batch above the reject watermark', function (): void {
    $buffer = new FakeEventBuffer;
    $buffer->depth = 250;

    $result = ingestor($buffer)->ingest([rawEvent(['priority' => 'operational'])]);

    expect($result->rejected)->toBeTrue()
        ->and($result->retryAfterSeconds)->toBe(10)
        ->and($buffer->appended)->toBeEmpty();
});

it('keeps response rows aligned with submission order', function (): void {
    $buffer = new FakeEventBuffer;
    $duplicate = rawEvent();
    $buffer->seen[strtolower($duplicate['event_id'])] = true;

    $result = ingestor($buffer)->ingest([
        rawEvent(['type' => 'BAD TYPE']),
        $duplicate,
        rawEvent(),
    ]);

    expect(array_map(fn (array $row): EventStatus => $row['status'], $result->rows))
        ->toBe([EventStatus::Invalid, EventStatus::Duplicate, EventStatus::Accepted]);
});

it('refuses inverted backpressure thresholds', function (): void {
    ingestor(new FakeEventBuffer, shedAbove: 300, rejectAbove: 200);
})->throws(InvalidArgumentException::class);

it('refuses a reject watermark at or past the stream maxlen', function (): void {
    Ingestor::assertSaneThresholds(100, 200, maxlen: 200);
})->throws(InvalidArgumentException::class);
