<?php

declare(strict_types=1);

namespace App\Domain\Billing\Webhooks\Handlers;

use App\Domain\Billing\Models\WebhookEvent;
use App\Domain\Billing\Payments\ApplyChargeOutcome;
use App\Domain\Billing\Payments\ApplyRefund;
use App\Domain\Billing\Payments\PspEvent;
use App\Domain\Billing\Payments\PspRefundEvent;
use App\Domain\Billing\Webhooks\WebhookHandler;
use App\Domain\Billing\Webhooks\WebhookOutcome;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * PSP deliveries. Charge outcomes go to the Phase 3 funnel unchanged —
 * duplicates and reordering still die there, under the intent row lock —
 * and refunds go to their own. Types we do not model are acknowledged and
 * ignored: a provider adds event types without asking, and a non-2xx for
 * "I don't know this one" would have it redelivered forever.
 */
final readonly class PspWebhookHandler implements WebhookHandler
{
    private const RETRY_OUT_OF_ORDER_SECONDS = 30;

    public function __construct(
        private ApplyChargeOutcome $outcomes,
        private ApplyRefund $refunds,
    ) {}

    public function handle(WebhookEvent $event): WebhookOutcome
    {
        $payload = $event->decodedPayload();

        try {
            return match ($event->type) {
                'charge.succeeded', 'charge.failed' => WebhookOutcome::processed(
                    $this->outcomes->apply(PspEvent::fromWebhookPayload($payload)),
                ),
                'charge.refunded' => $this->refund(PspRefundEvent::fromWebhookPayload($payload)),
                default => WebhookOutcome::processed('ignored'),
            };
        } catch (InvalidArgumentException $e) {
            // Malformed after signature verification = the PSP (mock) has a
            // bug. Retrying re-parses the same bytes; drop and say so.
            Log::error('Discarding malformed PSP webhook.', [
                'webhook_event_id' => $event->id,
                'reason' => $e->getMessage(),
            ]);

            return WebhookOutcome::discarded('malformed');
        }
    }

    private function refund(PspRefundEvent $refund): WebhookOutcome
    {
        $result = $this->refunds->apply($refund);

        if ($result === 'out_of_order') {
            // The refund outran the charge outcome (reordered delivery).
            // Wait for it; if it never comes, reconciliation settles both.
            return WebhookOutcome::retryLater($result, self::RETRY_OUT_OF_ORDER_SECONDS);
        }

        return WebhookOutcome::processed($result);
    }
}
