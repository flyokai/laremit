<?php

declare(strict_types=1);

namespace App\Domain\Billing\Jobs;

use App\Domain\Billing\Exceptions\PspUnavailable;
use App\Domain\Billing\Payments\ChargeProcessor;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\ThrottlesExceptions;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Charge one payment intent. Retries are the point: PspUnavailable throws
 * escape the processor so the queue re-runs this with backoff, and every
 * run reuses the intent's single PSP idempotency key — N attempts, at most
 * one charge.
 *
 * Rides the payments lane (ADR-007): its own connection, queue and worker
 * pool, so a flooded events queue cannot delay a charge, and the lane's
 * retry_after (90s) safely exceeds this job's timeout (60s).
 *
 * The retry budget is a DEADLINE, not an attempt count, because the PSP
 * circuit breaker below releases jobs while open and every release burns an
 * attempt — a $tries budget would be spent by the breaker itself, failing
 * jobs that never reached the PSP. Thirty minutes of retrying, then the
 * intent stays `processing` for reconciliation to settle.
 *
 * WithoutOverlapping (per intent) removes intra-intent concurrency: two
 * workers can never race the same intent's PSP call. Cross-path races
 * (webhook vs job) remain and are ApplyChargeOutcome's job to serialize.
 */
final class ChargeJob implements ShouldQueue
{
    use Queueable;

    /** Must stay under the payments connection's retry_after. */
    public int $timeout = 60;

    /**
     * PSP-connectivity failures never reach this counter — the breaker
     * swallows and releases them. What's left is our own bugs (an
     * unexpected throw from the outcome path), and retrying a bug for the
     * full thirty-minute window helps nobody: three strikes, fail, page.
     */
    public int $maxExceptions = 3;

    /** @var list<int> */
    public array $backoff = [2, 5, 15, 30];

    public function __construct(public int $paymentIntentId)
    {
        $this->onConnection('payments');
        $this->onQueue('payments');
    }

    /**
     * Computed at dispatch time, so a reconciliation re-dispatch of a stuck
     * intent arms a fresh thirty-minute window.
     */
    public function retryUntil(): DateTimeInterface
    {
        return CarbonImmutable::now()->addMinutes(30);
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [
            // Circuit breaker, first so an open circuit is checked before the
            // overlap lock is even taken. Ten PSP-connectivity failures in
            // ten minutes opens it; while open, every charge job is released
            // untried instead of burning a worker (and a PSP connection
            // timeout) each on a provider that is down. The key is shared
            // across all charge jobs — the PSP is one dependency, so it gets
            // one breaker. Only PspUnavailable counts: a decline is an
            // answer, not an outage (and it never throws anyway). Matched
            // throws are swallowed and the job released a minute out —
            // breaker-paced retries replace $backoff for connectivity
            // failures, always under the same PSP idempotency key.
            (new ThrottlesExceptions(10, 10 * 60))
                ->by('psp')
                ->backoff(1)
                ->when(static fn (Throwable $e): bool => $e instanceof PspUnavailable),
            (new WithoutOverlapping("charge:{$this->paymentIntentId}"))
                ->releaseAfter(10)
                ->expireAfter(120),
        ];
    }

    public function handle(ChargeProcessor $processor): void
    {
        $processor->process($this->paymentIntentId);
    }

    public function failed(?Throwable $exception): void
    {
        // Deadline exhausted with no definitive answer. The intent stays
        // `processing` — the truthful state — to be settled by the webhook
        // or swept by Phase 4's reconciliation. Marking it failed here would
        // be a guess, and guessing about money is how ledgers stop balancing.
        Log::critical('ChargeJob exhausted its retry window without a definitive PSP answer.', [
            'payment_intent_id' => $this->paymentIntentId,
            'exception' => $exception?->getMessage(),
        ]);
    }
}
