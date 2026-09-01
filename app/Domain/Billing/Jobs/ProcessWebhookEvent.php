<?php

declare(strict_types=1);

namespace App\Domain\Billing\Jobs;

use App\Domain\Billing\Enums\Store;
use App\Domain\Billing\Enums\WebhookEventStatus;
use App\Domain\Billing\Exceptions\StoreUnavailable;
use App\Domain\Billing\Models\WebhookEvent;
use App\Domain\Billing\Webhooks\Handlers\AppleNotificationHandler;
use App\Domain\Billing\Webhooks\Handlers\GoogleNotificationHandler;
use App\Domain\Billing\Webhooks\Handlers\PspWebhookHandler;
use App\Domain\Billing\Webhooks\WebhookHandler;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\ThrottlesExceptions;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Apply one persisted delivery. The endpoint answered 200 long ago; from
 * here the row is the unit of work, and its `status` is the idempotency
 * guard: a job that finds anything but `pending` returns, so the reaper
 * and a duplicate dispatch cost nothing.
 *
 * Rides the payments lane (ADR-007): applying a charge outcome is money
 * movement, and it must not queue behind a reactions backlog.
 *
 * The retry budget is a deadline plus an exception cap, not $tries: the
 * store circuit breaker below and the wantsRetry release loop both burn
 * attempts without doing work, so counting attempts would fail rows the
 * job never really tried. Two hours of releases, at most eight actual
 * handler exceptions, then the row is marked failed — reconciliation is
 * the backstop, not a retry loop (WebhookHandler's contract: exceptions
 * are transient by definition).
 */
final class ProcessWebhookEvent implements ShouldQueue
{
    use Queueable;

    public int $maxExceptions = 8;

    /** Must stay under the payments connection's retry_after. */
    public int $timeout = 60;

    /** @var list<int> */
    public array $backoff = [5, 30, 120];

    public function __construct(public int $webhookEventId)
    {
        $this->onConnection('payments');
        $this->onQueue('payments');
    }

    public function retryUntil(): DateTimeInterface
    {
        return CarbonImmutable::now()->addHours(2);
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [
            // Store-connectivity breaker, keyed apart from the PSP's: the
            // app stores and the PSP fail independently, so tripping one
            // must not park the other's jobs. StoreUnavailable only — a
            // handler's own bug should fail fast through maxExceptions,
            // not masquerade as an outage. Matched throws are swallowed
            // and released a minute out, breaker-paced.
            (new ThrottlesExceptions(10, 10 * 60))
                ->by('stores')
                ->backoff(1)
                ->when(static fn (Throwable $e): bool => $e instanceof StoreUnavailable),
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
