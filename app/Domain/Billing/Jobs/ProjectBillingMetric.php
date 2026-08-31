<?php

declare(strict_types=1);

namespace App\Domain\Billing\Jobs;

use App\Domain\Events\Support\Envelope;
use App\Domain\Outbox\ConsumeOnce;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The first real domain reaction (pays off tech-debt #9): billing.* events
 * become per-day operational counters — activations, cancellations, payment
 * outcomes, refunds.
 *
 * A counter increment is the textbook NON-idempotent effect; run twice, it
 * lies. Every layer above this job is allowed to deliver it twice — the
 * reacted-marker is best-effort, the stream redelivers, the queue retries —
 * so exactly-once lives here, at the effect: ConsumeOnce puts the
 * consumption marker and the increment in one transaction, and the
 * deterministic event id (stable across every redelivery path, relay
 * crashes included) is what the marker keys on.
 */
final class ProjectBillingMetric implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [5, 30];

    private const METRICS = [
        'billing.payment.succeeded' => 'payments_succeeded',
        'billing.payment.failed' => 'payments_failed',
        'billing.payment.refunded' => 'refunds',
        'billing.subscription.activated' => 'activations',
        'billing.subscription.canceled' => 'cancellations',
        'billing.subscription.revoked' => 'revocations',
    ];

    public function __construct(public Envelope $envelope) {}

    public function handle(): void
    {
        $metric = self::METRICS[$this->envelope->type] ?? null;

        if ($metric === null) {
            // The reaction map routed a type this job has no bucket for —
            // config drift, not data corruption. Count nothing, say so.
            Log::warning('No billing metric bucket for event type.', ['type' => $this->envelope->type]);

            return;
        }

        $day = $this->envelope->occurredAt->utc()->toDateString();

        ConsumeOnce::apply('billing_metrics', $this->envelope->eventId, static function () use ($metric, $day): void {
            // The increment happens in the database, atomically against the
            // (day, metric) unique key: concurrent consumers of different
            // events can bump the same counter without a lost update.
            DB::table('billing_metrics')->upsert(
                [['metric_date' => $day, 'metric' => $metric, 'value' => 1]],
                ['metric_date', 'metric'],
                ['value' => DB::raw('value + 1')],
            );
        });
    }

    public function failed(?Throwable $exception): void
    {
        // The queue's failed_jobs row is this job's dead letter (replay:
        // queue:retry / Horizon). The counter self-heals nothing, so be loud.
        Log::critical('Billing metric projection exhausted its retries.', [
            'event_id' => $this->envelope->eventId,
            'type' => $this->envelope->type,
            'exception' => $exception?->getMessage(),
        ]);
    }
}
