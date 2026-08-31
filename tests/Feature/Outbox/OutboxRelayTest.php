<?php

declare(strict_types=1);

use App\Domain\Events\Enums\Priority;
use App\Domain\Outbox\Models\OutboxMessage;
use App\Domain\Outbox\Outbox;
use App\Domain\Outbox\OutboxRelay;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    $this->buffer = fakeEventBuffer();
});

/** @return array<string, mixed> a raw outbox row the publisher would refuse to write */
function poisonOutboxRow(array $overrides = []): array
{
    $now = CarbonImmutable::now()->format('Y-m-d H:i:s');

    return array_merge([
        'aggregate_type' => 'subscription',
        'aggregate_id' => '1',
        'type' => 'billing.subscription.activated',
        'schema_version' => 1,
        'payload' => '{"user_id":1,"product":"Not A Slug"}',
        'idempotency_key' => 'poison:'.Illuminate\Support\Str::ulid(),
        'occurred_at' => $now,
        'available_at' => $now,
        'created_at' => $now,
    ], $overrides);
}

it('relays pending messages as operational envelopes and marks them dispatched', function (): void {
    $outbox = app(Outbox::class);
    $outbox->publish(outboxDomainEvent(['type' => 'billing.subscription.activated', 'userId' => 11]));
    $outbox->publish(outboxDomainEvent(['type' => 'billing.payment.succeeded', 'userId' => 12, 'product' => 'vpn']));

    $result = app(OutboxRelay::class)->relayBatch(10);

    expect($result)->toMatchArray(['claimed' => 2, 'dispatched' => 2, 'duplicates' => 0, 'dead' => 0, 'rejected' => false])
        ->and(OutboxMessage::query()->whereNull('dispatched_at')->count())->toBe(0);

    [$first, $second] = $this->buffer->appended;
    $messages = OutboxMessage::query()->orderBy('id')->get()->all();

    expect($first->type)->toBe('billing.subscription.activated')
        ->and($first->eventId)->toBe($messages[0]->eventId())
        ->and($first->priority)->toBe(Priority::Operational)
        ->and($first->userId)->toBe(11)
        ->and($first->product)->toBe('edtech')
        ->and($second->type)->toBe('billing.payment.succeeded')
        ->and($second->product)->toBe('vpn');

    // Nothing left: the next pass is a no-op.
    expect(app(OutboxRelay::class)->relayBatch(10)['claimed'])->toBe(0);
});

it('leaves future-dated messages alone until they become available', function (): void {
    app(Outbox::class)->publish(outboxDomainEvent());
    OutboxMessage::query()->update(['available_at' => CarbonImmutable::now()->addHour()]);

    expect(app(OutboxRelay::class)->relayBatch(10)['claimed'])->toBe(0)
        ->and($this->buffer->appended)->toBe([]);
});

it('marks a message already on the stream dispatched without appending it again', function (): void {
    app(Outbox::class)->publish(outboxDomainEvent());
    $message = OutboxMessage::query()->firstOrFail();

    // A previous relay pass published this id and died before marking.
    $this->buffer->seen[$message->eventId()] = true;

    $result = app(OutboxRelay::class)->relayBatch(10);

    expect($result)->toMatchArray(['claimed' => 1, 'dispatched' => 1, 'duplicates' => 1, 'dead' => 0])
        ->and($this->buffer->appended)->toBe([])
        ->and($message->refresh()->dispatched_at)->not->toBeNull();
});

it('dead-letters a message that fails envelope validation and never re-claims it', function (): void {
    OutboxMessage::query()->insert(poisonOutboxRow());

    $result = app(OutboxRelay::class)->relayBatch(10);
    $message = OutboxMessage::query()->firstOrFail();

    expect($result['dead'])->toBe(1)
        ->and($this->buffer->appended)->toBe([])
        ->and($message->dead_lettered_at)->not->toBeNull()
        ->and($message->last_error)->toContain('product')
        ->and(app(OutboxRelay::class)->relayBatch(10)['claimed'])->toBe(0);
});

it('holds every message when the stream applies backpressure', function (): void {
    app(Outbox::class)->publish(outboxDomainEvent());
    app(Outbox::class)->publish(outboxDomainEvent());

    $this->buffer->depth = (int) config('events.backpressure.reject_all_above');

    $result = app(OutboxRelay::class)->relayBatch(10);

    expect($result['rejected'])->toBeTrue()
        ->and($result['retry_after'])->toBe((int) config('events.backpressure.retry_after_seconds'))
        ->and($this->buffer->appended)->toBe([])
        ->and(OutboxMessage::query()->whereNull('dispatched_at')->count())->toBe(2);
});

it('relays in insertion order, batch by batch, via the worker command', function (): void {
    foreach ([1, 2, 3] as $n) {
        app(Outbox::class)->publish(outboxDomainEvent(['aggregateId' => (string) $n]));
    }

    $this->artisan('outbox:relay', ['--once' => true, '--batch' => '2'])->assertSuccessful();

    expect(OutboxMessage::query()->whereNotNull('dispatched_at')->pluck('aggregate_id')->all())->toBe(['1', '2']);

    $this->artisan('outbox:relay', ['--once' => true, '--batch' => '2'])->assertSuccessful();

    expect(OutboxMessage::query()->whereNull('dispatched_at')->count())->toBe(0)
        ->and(array_map(static fn ($e) => $e->eventId, $this->buffer->appended))
        ->toBe(OutboxMessage::query()->orderBy('id')->get()->map(fn (OutboxMessage $m): string => $m->eventId())->all());
});
