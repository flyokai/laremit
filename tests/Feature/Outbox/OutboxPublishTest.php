<?php

declare(strict_types=1);

use App\Domain\Outbox\DomainEvent;
use App\Domain\Outbox\Models\OutboxMessage;
use App\Domain\Outbox\Outbox;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

it('records a fact once and reports the duplicate as already recorded', function (): void {
    $outbox = app(Outbox::class);
    $event = outboxDomainEvent(['idempotencyKey' => 'subscription:1:active:2026-08-31 10:00:00.000']);

    expect($outbox->publish($event))->toBeTrue()
        ->and($outbox->publish($event))->toBeFalse()
        ->and(OutboxMessage::query()->count())->toBe(1);
});

it('rides the caller\'s transaction: a rollback takes the message with it', function (): void {
    DB::beginTransaction();

    app(Outbox::class)->publish(outboxDomainEvent());

    expect(OutboxMessage::query()->count())->toBe(1);

    DB::rollBack();

    expect(OutboxMessage::query()->count())->toBe(0);
});

it('refuses to publish outside a database transaction', function (): void {
    // Unwrap RefreshDatabase's test transaction — nothing has been written
    // yet, so committing it is a no-op — and restore it afterwards so the
    // teardown rollback stays balanced.
    DB::commit();

    try {
        expect(fn (): bool => app(Outbox::class)->publish(outboxDomainEvent()))
            ->toThrow(LogicException::class, 'dual-write');
    } finally {
        DB::beginTransaction();
    }
});

it('folds the identity into the stored payload', function (): void {
    app(Outbox::class)->publish(outboxDomainEvent([
        'userId' => 42,
        'product' => 'vpn',
        'payload' => ['subscription_id' => 9, 'status' => 'active'],
    ]));

    $payload = OutboxMessage::query()->firstOrFail()->decodedPayload();

    expect($payload['user_id'])->toBe(42)
        ->and($payload['product'])->toBe('vpn')
        ->and($payload['subscription_id'])->toBe(9);
});

it('derives a stable, well-formed event id from the idempotency key', function (): void {
    app(Outbox::class)->publish(outboxDomainEvent(['idempotencyKey' => 'payment:5:settled']));
    app(Outbox::class)->publish(outboxDomainEvent(['idempotencyKey' => 'payment:6:settled']));

    [$first, $second] = OutboxMessage::query()->orderBy('id')->get()->all();

    expect($first->eventId())->toBe($first->eventId())
        ->and($first->eventId())->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-5[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/')
        ->and($first->eventId())->not->toBe($second->eventId());
});

it('preserves the fact\'s millisecond clock', function (): void {
    $at = CarbonImmutable::parse('2026-08-30 12:00:00.123');

    app(Outbox::class)->publish(outboxDomainEvent(['occurredAt' => $at]));

    $message = OutboxMessage::query()->firstOrFail();

    expect($message->envelopeInput()['occurred_at'])->toBe('2026-08-30T12:00:00.123000Z');
});

it('rejects a malformed event at construction, in the publisher\'s transaction', function (): void {
    expect(fn (): DomainEvent => outboxDomainEvent(['type' => 'Not A Type']))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn (): DomainEvent => outboxDomainEvent(['product' => 'Bad Product']))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn (): DomainEvent => outboxDomainEvent(['userId' => 0]))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn (): DomainEvent => outboxDomainEvent(['idempotencyKey' => '']))
        ->toThrow(InvalidArgumentException::class);
});
