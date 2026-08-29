<?php

declare(strict_types=1);

namespace App\Domain\Billing\Webhooks\Handlers;

use App\Domain\Billing\Models\WebhookEvent;
use App\Domain\Billing\Stores\Apple\AppleNotificationParser;
use App\Domain\Billing\Stores\StoreSubscriptionProjector;
use App\Domain\Billing\Webhooks\WebhookHandler;
use App\Domain\Billing\Webhooks\WebhookOutcome;
use App\Support\Jws\JwsException;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * App Store Server Notifications V2. The controller already verified the
 * outer signature before persisting; the handler verifies again from the
 * stored bytes (cheap, and the row may be reprocessed months later by
 * someone who never saw the request), derives the absolute state from the
 * signed transaction/renewal info, and hands it to the projector — which
 * is where stale deliveries are rejected and duplicates become no-ops.
 */
final readonly class AppleNotificationHandler implements WebhookHandler
{
    public function __construct(
        private AppleNotificationParser $parser,
        private StoreSubscriptionProjector $projector,
    ) {}

    public function handle(WebhookEvent $event): WebhookOutcome
    {
        $signedPayload = $event->decodedPayload()['signedPayload'] ?? null;

        if (! is_string($signedPayload)) {
            return WebhookOutcome::discarded('malformed');
        }

        try {
            $notification = $this->parser->parse($signedPayload);
        } catch (JwsException|InvalidArgumentException $e) {
            Log::error('Discarding unverifiable or malformed App Store notification.', [
                'webhook_event_id' => $event->id,
                'reason' => $e->getMessage(),
            ]);

            return WebhookOutcome::discarded($e instanceof JwsException ? 'bad_signature' : 'malformed');
        }

        $result = $this->projector->project($notification->snapshot);

        return $result->isApplicable()
            ? WebhookOutcome::processed($result->verdict)
            : WebhookOutcome::discarded($result->verdict);
    }
}
