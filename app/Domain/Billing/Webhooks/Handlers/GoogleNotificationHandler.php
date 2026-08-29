<?php

declare(strict_types=1);

namespace App\Domain\Billing\Webhooks\Handlers;

use App\Domain\Billing\Enums\Store;
use App\Domain\Billing\Enums\SubscriptionStatus;
use App\Domain\Billing\Models\Subscription;
use App\Domain\Billing\Models\WebhookEvent;
use App\Domain\Billing\Stores\Google\GoogleNotification;
use App\Domain\Billing\Stores\Google\GoogleNotificationParser;
use App\Domain\Billing\Stores\StoreClient;
use App\Domain\Billing\Stores\StoreSubscriptionProjector;
use App\Domain\Billing\Webhooks\WebhookHandler;
use App\Domain\Billing\Webhooks\WebhookOutcome;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Real-Time Developer Notifications. RTDN gives no choice about the
 * design: the message carries a purchase token and a type, so the
 * handler treats it as a trigger — dedupe (done at the edge on messageId),
 * re-fetch the purchase from the Play Developer API, and project THAT.
 * A forged notification can at most make us re-read the truth.
 *
 * Two exceptions to "state, not type": REVOKED is a fact the current
 * object no longer shows (it just says expired), so that one type
 * upgrades the projected status; and a fresh purchase must be
 * acknowledged within three days or Google refunds it.
 */
final readonly class GoogleNotificationHandler implements WebhookHandler
{
    public function __construct(
        private StoreClient $store,
        private StoreSubscriptionProjector $projector,
    ) {}

    public function handle(WebhookEvent $event): WebhookOutcome
    {
        try {
            $notification = GoogleNotificationParser::parse($event->decodedPayload());
        } catch (InvalidArgumentException $e) {
            Log::error('Discarding malformed RTDN envelope.', [
                'webhook_event_id' => $event->id,
                'reason' => $e->getMessage(),
            ]);

            return WebhookOutcome::discarded('malformed');
        }

        if (! $notification->isSubscriptionNotification() || $notification->purchaseToken === null) {
            return WebhookOutcome::processed('ignored');
        }

        // Coalesce: a notification older than what is already applied would
        // re-fetch current truth and then be rejected as stale anyway. Skip
        // the round trip; the watermark already covers it.
        $watermark = Subscription::query()
            ->where('store', Store::Google->value)
            ->where('store_original_transaction_id', $notification->purchaseToken)
            ->value('last_event_at');

        if (is_string($watermark) && ! $notification->eventTime->isAfter(CarbonImmutable::parse($watermark, 'UTC'))) {
            return WebhookOutcome::processed('stale');
        }

        $snapshot = $this->store->fetchSubscription(
            Store::Google,
            $notification->purchaseToken,
            $notification->eventTime,
            $notification->typeName(),
        );

        if ($snapshot === null) {
            Log::warning('RTDN names a purchase token the Play API does not know; nothing to apply.', [
                'webhook_event_id' => $event->id,
            ]);

            return WebhookOutcome::discarded('unknown_purchase');
        }

        if ($notification->notificationType === GoogleNotification::REVOKED && $snapshot->status === SubscriptionStatus::Expired) {
            $snapshot = $snapshot->withStatus(SubscriptionStatus::Revoked, 'REVOKED');
        }

        $result = $this->projector->project($snapshot);

        if ($result->isApplicable() && $this->store->needsAcknowledgement(Store::Google, $notification->purchaseToken)) {
            $this->store->acknowledge(Store::Google, $notification->purchaseToken);
        }

        return $result->isApplicable()
            ? WebhookOutcome::processed($result->verdict)
            : WebhookOutcome::discarded($result->verdict);
    }
}
