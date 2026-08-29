<?php

declare(strict_types=1);

namespace App\Domain\Billing\Webhooks;

use App\Domain\Billing\Enums\Store;
use App\Domain\Billing\Enums\WebhookEventStatus;
use App\Domain\Billing\Jobs\ProcessWebhookEvent;
use App\Domain\Billing\Models\WebhookEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The provider-agnostic half of every webhook endpoint: persist the raw
 * delivery, then queue its processing. Three details carry the design:
 *
 *  1. INSERT IGNORE on UNIQUE(provider, provider_event_id). A redelivery
 *     is a no-op the database decides — no read-then-write, so two
 *     simultaneous redeliveries cannot both think they are first.
 *  2. Dispatch on `status = pending`, not on "did I just insert it". The
 *     two diverge exactly when the process died between insert and
 *     dispatch: the provider retries, the row already exists, and a
 *     wasRecentlyCreated check would answer 200 and never process it.
 *     Silent loss, acknowledged. Re-dispatching an already-queued event
 *     is harmless instead — the job is idempotent.
 *  3. afterCommit, so a caller that wraps this in a transaction can never
 *     hand the job a row the worker cannot see yet.
 */
final readonly class WebhookInbox
{
    public function receive(
        Store $provider,
        string $providerEventId,
        string $type,
        string $rawPayload,
        ?CarbonImmutable $providerCreatedAt,
    ): InboxReceipt {
        $now = CarbonImmutable::now();

        $inserted = DB::table('webhook_events')->insertOrIgnore([
            'provider' => $provider->value,
            'provider_event_id' => $providerEventId,
            'type' => mb_substr($type, 0, 64),
            'payload' => $rawPayload,
            'provider_created_at' => $providerCreatedAt?->format('Y-m-d H:i:s'),
            'received_at' => $now->format('Y-m-d H:i:s'),
            'status' => WebhookEventStatus::Pending->value,
            'attempts' => 0,
        ]);

        $event = WebhookEvent::query()
            ->where('provider', $provider->value)
            ->where('provider_event_id', $providerEventId)
            ->firstOrFail();

        $dispatched = false;

        if ($event->status === WebhookEventStatus::Pending) {
            ProcessWebhookEvent::dispatch($event->id)->afterCommit();
            $dispatched = true;
        }

        return new InboxReceipt($event->id, duplicate: $inserted === 0, dispatched: $dispatched);
    }
}
