<?php

declare(strict_types=1);

namespace App\Domain\Billing\Jobs;

use App\Domain\Billing\Enums\Store;
use App\Domain\Billing\Enums\WebhookEventStatus;
use App\Domain\Billing\Models\WebhookEvent;
use App\Domain\Billing\Webhooks\Handlers\AppleNotificationHandler;
use App\Domain\Billing\Webhooks\Handlers\GoogleNotificationHandler;
use App\Domain\Billing\Webhooks\Handlers\PspWebhookHandler;
use App\Domain\Billing\Webhooks\WebhookHandler;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Apply one persisted delivery. The endpoint answered 200 long ago; from
 * here the row is the unit of work, and its `status` is the idempotency
 * guard: a job that finds anything but `pending` returns, so the reaper
 * and a duplicate dispatch cost nothing.
 *
 * Exceptions are transient by contract (WebhookHandler): the queue retries
 * with backoff, and when it gives up the row is marked failed with the
 * reason — reconciliation is the backstop, not a retry loop.
 */
final class ProcessWebhookEvent implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    /** @var list<int> */
    public array $backoff = [5, 30, 120];

    public function __construct(public int $webhookEventId) {}

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("webhook:{$this->webhookEventId}"))
                ->releaseAfter(10)
                ->expireAfter(300),
        ];
    }

    public function handle(Container $container): void
    {
        $event = WebhookEvent::query()->find($this->webhookEventId);

        if ($event === null || $event->status !== WebhookEventStatus::Pending) {
            return;
        }

        WebhookEvent::query()->whereKey($event->id)->increment('attempts');

        try {
            $outcome = $this->handlerFor($container, $event->provider)->handle($event);
        } catch (Throwable $e) {
            WebhookEvent::query()->whereKey($event->id)->update(['last_error' => mb_substr($e->getMessage(), 0, 255)]);

            throw $e;
        }

        if ($outcome->wantsRetry()) {
            WebhookEvent::query()->whereKey($event->id)->update(['outcome' => $outcome->outcome]);

            $this->release($outcome->retryAfterSeconds ?? 30);

            return;
        }

        // Guarded settle: only a pending row moves, exactly once.
        WebhookEvent::query()
            ->whereKey($event->id)
            ->where('status', WebhookEventStatus::Pending->value)
            ->update([
                'status' => $outcome->status->value,
                'outcome' => $outcome->outcome,
                'processed_at' => CarbonImmutable::now(),
            ]);
    }

    public function failed(?Throwable $exception): void
    {
        WebhookEvent::query()
            ->whereKey($this->webhookEventId)
            ->where('status', WebhookEventStatus::Pending->value)
            ->update([
                'status' => WebhookEventStatus::Failed->value,
                'last_error' => mb_substr($exception?->getMessage() ?? 'attempts exhausted', 0, 255),
                'processed_at' => CarbonImmutable::now(),
            ]);

        Log::critical('Webhook processing exhausted its retries; reconciliation must settle the entity.', [
            'webhook_event_id' => $this->webhookEventId,
            'exception' => $exception?->getMessage(),
        ]);
    }

    private function handlerFor(Container $container, Store $provider): WebhookHandler
    {
        return match ($provider) {
            Store::Psp => $container->make(PspWebhookHandler::class),
            Store::Apple => $container->make(AppleNotificationHandler::class),
            Store::Google => $container->make(GoogleNotificationHandler::class),
        };
    }
}
