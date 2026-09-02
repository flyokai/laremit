<?php

declare(strict_types=1);

use App\Domain\Events\Consumers\ArchiveConsumer;
use App\Domain\Events\Contracts\EventBuffer;
use App\Domain\Events\Models\ArchivedEvent;
use App\Domain\Events\Projections\DailyActiveUsers;
use App\Domain\Events\Stream\PendingEvent;
use App\Domain\Events\Stream\RedisEventStream;
use App\Domain\Events\Support\Envelope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

/**
 * Integration tests against the real stream and cache Redis instances
 * (database 9, test prefix — see phpunit.xml). They skip when the containers
 * are down so the suite stays runnable anywhere.
 */
beforeEach(function (): void {
    foreach (['stream', 'cache'] as $connection) {
        // Refuse to run against anything but the dedicated test database.
        if ((string) config("database.redis.{$connection}.database") !== '9') {
            $this->fail("Redis [{$connection}] is not on test database 9; refusing to flush.");
        }

        try {
            Redis::connection($connection)->ping();
        } catch (Throwable) {
            $this->markTestSkipped('Event Redis instances are not reachable; is the compose stack up?');
        }

        Redis::connection($connection)->flushdb();
    }
});

/** @return array<string, mixed> */
function pipelineEvent(array $overrides = []): array
{
    return array_merge([
        'event_id' => (string) Str::uuid(),
        'type' => 'video.watched',
        'schema_version' => 2,
        'occurred_at' => now()->toISOString(),
        'user_id' => random_int(1, 1_000_000),
        'product' => 'edtech',
        'priority' => 'analytics',
        'payload' => ['position_ms' => 1000],
    ], $overrides);
}

function postEvents(array $events): Illuminate\Testing\TestResponse
{
    return test()->postJson('/v1/events', ['events' => $events], [
        'Authorization' => 'Bearer test-token',
    ]);
}

it('archives every accepted event exactly once, end to end', function (): void {
    $events = array_map(fn (): array => pipelineEvent(), range(1, 25));

    postEvents($events)->assertStatus(202)->assertJsonPath('accepted', 25);

    $this->artisan('events:work', ['group' => 'archive', '--once' => true, '--block' => 100])
        ->assertSuccessful();

    expect(ArchivedEvent::query()->count())->toBe(25)
        ->and(ArchivedEvent::query()->pluck('event_id')->sort()->values()->all())
        ->toBe(collect($events)->pluck('event_id')->map(fn (string $id): string => strtolower($id))->sort()->values()->all());
});

it('dedups a retried batch at ingest so the stream never sees it twice', function (): void {
    $events = array_map(fn (): array => pipelineEvent(), range(1, 10));

    postEvents($events)->assertStatus(202)->assertJsonPath('accepted', 10);
    postEvents($events)->assertStatus(202)->assertJsonPath('accepted', 0)->assertJsonPath('duplicates', 10);

    expect(app(EventBuffer::class)->depth())->toBe(10);
});

it('projects DAU into HLL and the activity bitmap', function (): void {
    $day = CarbonImmutable::now()->utc();

    postEvents([
        pipelineEvent(['user_id' => 101, 'product' => 'edtech']),
        pipelineEvent(['user_id' => 101, 'product' => 'edtech']), // same user again
        pipelineEvent(['user_id' => 202, 'product' => 'edtech']),
        pipelineEvent(['user_id' => 303, 'product' => 'vpn']),
        pipelineEvent(['user_id' => null]),
    ])->assertStatus(202);

    $this->artisan('events:work', ['group' => 'projections', '--once' => true, '--block' => 100])
        ->assertSuccessful();

    $dau = app(DailyActiveUsers::class);

    expect($dau->count('edtech', $day))->toBe(2)
        ->and($dau->count('vpn', $day))->toBe(1)
        ->and($dau->activeCount($day))->toBe(3)
        ->and($dau->wasActive(101, $day))->toBeTrue()
        ->and($dau->wasActive(999, $day))->toBeFalse();
});

it('dispatches a reaction job exactly once across redelivery', function (): void {
    Illuminate\Support\Facades\Bus::fake();
    config()->set('events.reactions.map', ['video.watched' => [Tests\Support\FakeReactionJob::class]]);

    postEvents([pipelineEvent()])->assertStatus(202);

    $buffer = app(EventBuffer::class);
    $consumer = app(App\Domain\Events\Consumers\ReactionConsumer::class);

    // First delivery: read, apply, but "crash" before ack.
    $buffer->ensureGroup('reactions');
    $first = $buffer->readNew('reactions', 'worker-a', 10, 100);
    $consumer->apply(array_map(fn (PendingEvent $e): Envelope => $e->envelope, $first));

    // Redelivery to a recovering worker: claim and apply again, then ack.
    $second = $buffer->claimAbandoned('reactions', 'worker-b', 0, 10);
    $consumer->apply(array_map(fn (PendingEvent $e): Envelope => $e->envelope, $second));
    $buffer->ack('reactions', array_map(fn (PendingEvent $e): string => $e->id, $second));

    expect($second)->toHaveCount(1);
    Illuminate\Support\Facades\Bus::assertDispatchedTimes(Tests\Support\FakeReactionJob::class, 1);
});

it('survives a consumer killed mid-batch with no loss and no double-count', function (): void {
    // The Phase 2 chaos deliverable, mechanically: worker A takes a batch of
    // 10, archives the first 4, and dies before acking anything. Worker B
    // claims the abandoned batch, applies all 10 (4 of them re-applies), and
    // acks. The archive must hold exactly 10 rows — nothing lost to the
    // crash, nothing double-counted by the redelivery.
    $events = array_map(fn (): array => pipelineEvent(), range(1, 10));
    postEvents($events)->assertStatus(202)->assertJsonPath('accepted', 10);

    /** @var RedisEventStream $buffer */
    $buffer = app(EventBuffer::class);
    $consumer = new ArchiveConsumer;

    $buffer->ensureGroup('archive');
    $batch = $buffer->readNew('archive', 'worker-a', 10, 100);
    expect($batch)->toHaveCount(10);

    // Worker A applies a partial batch, then is killed: no ack ever happens.
    $consumer->apply(array_map(
        fn (PendingEvent $e): Envelope => $e->envelope,
        array_slice($batch, 0, 4),
    ));
    expect(ArchivedEvent::query()->count())->toBe(4);

    // Worker B recovers the whole abandoned batch via XAUTOCLAIM...
    $claimed = $buffer->claimAbandoned('archive', 'worker-b', 0, 10);
    expect($claimed)->toHaveCount(10)
        ->and($claimed[0]->deliveries)->toBeGreaterThanOrEqual(2);

    // ...applies all of it — including the 4 already-archived events...
    $consumer->apply(array_map(fn (PendingEvent $e): Envelope => $e->envelope, $claimed));
    $buffer->ack('archive', array_map(fn (PendingEvent $e): string => $e->id, $claimed));

    // ...and the ledger of events is exact: 10 in, 10 archived, once each.
    expect(ArchivedEvent::query()->count())->toBe(10)
        ->and(ArchivedEvent::query()->distinct()->count('event_id'))->toBe(10)
        ->and($buffer->claimAbandoned('archive', 'worker-c', 0, 10))->toBeEmpty();
})->group('chaos');

it('dead-letters an entry that cannot be decoded instead of blocking the group', function (): void {
    /** @var RedisEventStream $buffer */
    $buffer = app(EventBuffer::class);

    // A corrupt producer writes garbage straight into the stream.
    Redis::connection('stream')->xadd((string) config('events.stream.key'), '*', ['e' => '{broken']);
    postEvents([pipelineEvent()])->assertStatus(202);

    $this->artisan('events:work', ['group' => 'archive', '--once' => true, '--block' => 100])
        ->assertSuccessful();

    expect(ArchivedEvent::query()->count())->toBe(1)
        ->and((int) Redis::connection('stream')->llen((string) config('events.consumers.dead_letter_key')))->toBe(1)
        ->and($buffer->claimAbandoned('archive', 'sweeper', 0, 10))->toBeEmpty();
});

it('feeds all three consumer groups from one stream entry', function (): void {
    postEvents([pipelineEvent(['user_id' => 7])])->assertStatus(202);

    foreach (['archive', 'projections', 'reactions'] as $group) {
        $this->artisan('events:work', ['group' => $group, '--once' => true, '--block' => 100])
            ->assertSuccessful();
    }

    expect(ArchivedEvent::query()->count())->toBe(1)
        ->and(app(DailyActiveUsers::class)->wasActive(7, CarbonImmutable::now()->utc()))->toBeTrue();
});
