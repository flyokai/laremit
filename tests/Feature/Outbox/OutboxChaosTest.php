<?php

declare(strict_types=1);

use App\Domain\Billing\Jobs\ProjectBillingMetric;
use App\Domain\Billing\Models\BillingMetric;
use App\Domain\Events\Consumers\ArchiveConsumer;
use App\Domain\Events\Models\ArchivedEvent;
use App\Domain\Outbox\Models\OutboxMessage;
use App\Domain\Outbox\Outbox;
use App\Domain\Outbox\OutboxRelay;
use Carbon\CarbonImmutable;

/**
 * The Phase 5 chaos deliverable: kill the relay between publish and
 * mark-dispatched, and prove consumer idempotency absorbs it — at every
 * layer the duplicate can reach.
 */
beforeEach(function (): void {
    $this->buffer = fakeEventBuffer();
});

/** Five distinct billing facts across the metric buckets. */
function publishChaosFacts(): void
{
    $outbox = app(Outbox::class);

    $outbox->publish(outboxDomainEvent(['type' => 'billing.subscription.activated', 'aggregateId' => '1']));
    $outbox->publish(outboxDomainEvent(['type' => 'billing.subscription.activated', 'aggregateId' => '2']));
    $outbox->publish(outboxDomainEvent(['type' => 'billing.subscription.canceled', 'aggregateId' => '1']));
    $outbox->publish(outboxDomainEvent(['type' => 'billing.payment.succeeded', 'aggregateType' => 'payment_intent']));
    $outbox->publish(outboxDomainEvent(['type' => 'billing.payment.failed', 'aggregateType' => 'payment_intent']));
}

it('absorbs a relay killed between publish and mark-dispatched', function (): void {
    publishChaosFacts();

    // The kill: events reach the stream, the claim transaction rolls back.
    $this->buffer->dieAfterAppend = 'relay killed between publish and mark-dispatched';

    expect(fn (): array => app(OutboxRelay::class)->relayBatch(10))->toThrow(RuntimeException::class);

    expect(count($this->buffer->appended))->toBe(5)
        ->and(OutboxMessage::query()->whereNull('dispatched_at')->count())->toBe(5);

    // Recovery is just the next pass: the deterministic event ids are
    // already marked seen, so the stream never receives a second copy.
    $this->buffer->dieAfterAppend = null;

    $result = app(OutboxRelay::class)->relayBatch(10);

    expect($result)->toMatchArray(['claimed' => 5, 'dispatched' => 5, 'duplicates' => 5, 'dead' => 0])
        ->and(count($this->buffer->appended))->toBe(5)
        ->and(OutboxMessage::query()->whereNull('dispatched_at')->count())->toBe(0);
});

it('keeps effects exactly-once even when the stream carries duplicates and consumers are redelivered', function (): void {
    publishChaosFacts();

    app(OutboxRelay::class)->relayBatch(10);

    // Worst case: the crash-retry happened AFTER the ingest dedup window
    // expired, so the stream holds two copies of every fact.
    $this->buffer->append(array_values($this->buffer->appended));

    expect(count($this->buffer->appended))->toBe(10);

    // And every consumer is delivered the whole lot twice — a crash after
    // apply but before ack redelivers the full batch.
    app(ArchiveConsumer::class)->apply($this->buffer->appended);
    app(ArchiveConsumer::class)->apply($this->buffer->appended);

    foreach ([1, 2] as $delivery) {
        foreach ($this->buffer->appended as $envelope) {
            (new ProjectBillingMetric($envelope))->handle();
        }
    }

    // Five facts happened; five facts are on record. Twenty deliveries of
    // ten stream entries changed nothing about that.
    $today = CarbonImmutable::now();

    expect(ArchivedEvent::query()->count())->toBe(5)
        ->and(BillingMetric::valueFor($today, 'activations'))->toBe(2)
        ->and(BillingMetric::valueFor($today, 'cancellations'))->toBe(1)
        ->and(BillingMetric::valueFor($today, 'payments_succeeded'))->toBe(1)
        ->and(BillingMetric::valueFor($today, 'payments_failed'))->toBe(1);
});
