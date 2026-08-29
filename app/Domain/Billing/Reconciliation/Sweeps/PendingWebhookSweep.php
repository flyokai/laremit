<?php

declare(strict_types=1);

namespace App\Domain\Billing\Reconciliation\Sweeps;

use App\Domain\Billing\Enums\WebhookEventStatus;
use App\Domain\Billing\Jobs\ProcessWebhookEvent;
use App\Domain\Billing\Models\WebhookEvent;
use App\Domain\Billing\Reconciliation\ReconciliationReport;
use App\Domain\Billing\Reconciliation\Sweep;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

/**
 * The reaper for the edge's one remaining gap: a delivery persisted as
 * `pending` and never dispatched — the process died between INSERT and
 * dispatch, or the queue lost the job. The provider already has its 200,
 * so nobody will resend it; this sweep is the only thing that notices.
 * Re-dispatching a row a worker is about to process anyway is harmless
 * (the job is idempotent on status), so the threshold only has to be
 * longer than an honest queue wait.
 */
final readonly class PendingWebhookSweep implements Sweep
{
    public function sweep(ReconciliationReport $report, CarbonImmutable $now, CarbonImmutable $windowStart): void
    {
        $threshold = $now->subMinutes((int) config('billing.webhooks.pending_after_minutes'));

        WebhookEvent::query()
            ->where('status', WebhookEventStatus::Pending->value)
            ->where('received_at', '<', $threshold)
            ->chunkById(100, function (Collection $events) use ($report): void {
                /** @var WebhookEvent $event */
                foreach ($events as $event) {
                    $report->scanned('pending_webhooks');

                    ProcessWebhookEvent::dispatch($event->id);

                    $report->noted('redispatched_webhook', ['webhook_event_id' => $event->id, 'provider' => $event->provider->value]);
                }
            });
    }
}
