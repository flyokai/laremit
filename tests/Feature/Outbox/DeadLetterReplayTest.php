<?php

declare(strict_types=1);

use App\Domain\Events\Enums\Priority;
use App\Domain\Events\Stream\PendingEvent;
use App\Domain\Events\Support\Envelope;
use App\Domain\Outbox\Models\OutboxMessage;
use App\Domain\Outbox\OutboxRelay;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->buffer = fakeEventBuffer();
});

it('replays a dead-lettered outbox message through the same pipeline once the defect is fixed', function (): void {
    OutboxMessage::query()->insert(poisonOutboxRow(['idempotency_key' => 'poison:fixed-later']));

    expect(app(OutboxRelay::class)->relayBatch(10)['dead'])->toBe(1);

    $message = OutboxMessage::query()->firstOrFail();

    // Replay without a fix: same pipeline, same verdict, parked again with a
    // fresh error — never a poison loop in the relay.
    $this->artisan('outbox:replay', ['--id' => [(string) $message->id]])->assertSuccessful();

    expect($message->refresh()->dead_lettered_at)->toBeNull()
        ->and(app(OutboxRelay::class)->relayBatch(10)['dead'])->toBe(1)
        ->and($message->refresh()->dead_lettered_at)->not->toBeNull();

    // Fix the defect, replay again: the message finally ships.
    OutboxMessage::query()->whereKey($message->id)->update(['payload' => '{"user_id":1,"product":"edtech"}']);

    $this->artisan('outbox:replay', ['--all' => true])->assertSuccessful();

    expect(app(OutboxRelay::class)->relayBatch(10)['dispatched'])->toBe(1)
        ->and(count($this->buffer->appended))->toBe(1)
        ->and($message->refresh()->dispatched_at)->not->toBeNull();
});

it('demands an explicit target before replaying anything', function (): void {
    $this->artisan('outbox:replay')->assertExitCode(2);
});

it('alarms on dead-lettered messages until they are replayed and delivered', function (): void {
    OutboxMessage::query()->insert(poisonOutboxRow(['payload' => '{"user_id":1,"product":"edtech"}']));

    // Healthy backlog, nothing dead: quiet.
    $this->artisan('outbox:status', ['--check' => true])->assertSuccessful();

    OutboxMessage::query()->update(['dead_lettered_at' => CarbonImmutable::now(), 'last_error' => 'poison']);

    $this->artisan('outbox:status', ['--check' => true])->assertFailed();

    $this->artisan('outbox:replay', ['--all' => true])->assertSuccessful();
    app(OutboxRelay::class)->relayBatch(10);

    $this->artisan('outbox:status', ['--check' => true])->assertSuccessful();
});

it('replays stream dead letters to every group and keeps what no longer decodes', function (): void {
    $envelope = new Envelope(
        (string) Str::uuid(),
        'billing.subscription.activated',
        1,
        CarbonImmutable::now(),
        7,
        'edtech',
        Priority::Operational,
        ['subscription_id' => 1],
        CarbonImmutable::now(),
    );

    $this->buffer->deadLetter(new PendingEvent('1-1', $envelope, 7), 'poison: 7 failed deliveries');
    $this->buffer->deadLetter(new PendingEvent('1-2', null, 1, ['e' => '{broken']), 'undecodable envelope');

    $this->artisan('events:replay')->assertSuccessful();

    expect(count($this->buffer->appended))->toBe(1)
        ->and($this->buffer->appended[0]->eventId)->toBe($envelope->eventId)
        ->and(count($this->buffer->deadLettered))->toBe(1);
});
