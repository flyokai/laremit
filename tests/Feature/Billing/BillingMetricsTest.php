<?php

declare(strict_types=1);

use App\Domain\Billing\Jobs\ProjectBillingMetric;
use App\Domain\Billing\Models\BillingMetric;
use App\Domain\Events\Enums\Priority;
use App\Domain\Events\Support\Envelope;
use App\Domain\Outbox\Models\DomainEventConsumption;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

function billingMetricEnvelope(string $type, ?CarbonImmutable $occurredAt = null, ?string $eventId = null): Envelope
{
    return new Envelope(
        $eventId ?? (string) Str::uuid(),
        $type,
        1,
        $occurredAt ?? CarbonImmutable::now(),
        7,
        'edtech',
        Priority::Operational,
        ['subscription_id' => 1],
        CarbonImmutable::now(),
    );
}

it('counts each billing event into its day bucket', function (): void {
    $today = CarbonImmutable::now();

    (new ProjectBillingMetric(billingMetricEnvelope('billing.subscription.activated')))->handle();
    (new ProjectBillingMetric(billingMetricEnvelope('billing.subscription.activated')))->handle();
    (new ProjectBillingMetric(billingMetricEnvelope('billing.payment.refunded')))->handle();

    expect(BillingMetric::valueFor($today, 'activations'))->toBe(2)
        ->and(BillingMetric::valueFor($today, 'refunds'))->toBe(1)
        ->and(BillingMetric::valueFor($today, 'payments_succeeded'))->toBe(0);
});

it('applies exactly once per event id, however often the job is redelivered', function (): void {
    $envelope = billingMetricEnvelope('billing.subscription.revoked');

    (new ProjectBillingMetric($envelope))->handle();
    (new ProjectBillingMetric($envelope))->handle();
    (new ProjectBillingMetric($envelope))->handle();

    expect(BillingMetric::valueFor(CarbonImmutable::now(), 'revocations'))->toBe(1)
        ->and(DomainEventConsumption::query()->where('consumer', 'billing_metrics')->count())->toBe(1);
});

it('buckets by the event\'s UTC day, not the processing day', function (): void {
    $yesterday = CarbonImmutable::now()->subDay();

    (new ProjectBillingMetric(billingMetricEnvelope('billing.payment.succeeded', $yesterday)))->handle();
    (new ProjectBillingMetric(billingMetricEnvelope('billing.payment.succeeded')))->handle();

    expect(BillingMetric::valueFor($yesterday, 'payments_succeeded'))->toBe(1)
        ->and(BillingMetric::valueFor(CarbonImmutable::now(), 'payments_succeeded'))->toBe(1);
});

it('declines to count a type it has no bucket for, without consuming it', function (): void {
    (new ProjectBillingMetric(billingMetricEnvelope('billing.subscription.paused')))->handle();

    expect(BillingMetric::query()->count())->toBe(0)
        ->and(DomainEventConsumption::query()->count())->toBe(0);
});
