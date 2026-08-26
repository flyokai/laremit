<?php

declare(strict_types=1);

namespace App\Domain\Billing\Jobs;

use App\Domain\Billing\Payments\ChargeProcessor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Charge one payment intent. Retries are the point: PspUnavailable throws
 * escape the processor so the queue re-runs this with backoff, and every
 * run reuses the intent's single PSP idempotency key — N attempts, at most
 * one charge.
 *
 * WithoutOverlapping (per intent) removes intra-intent concurrency: two
 * workers can never race the same intent's PSP call. Cross-path races
 * (webhook vs job) remain and are ApplyChargeOutcome's job to serialize.
 */
final class ChargeJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [2, 5, 15, 30];

    public function __construct(public int $paymentIntentId) {}

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [
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
        // Attempts exhausted with no definitive answer. The intent stays
        // `processing` — the truthful state — to be settled by the webhook
        // or swept by Phase 4's reconciliation. Marking it failed here would
        // be a guess, and guessing about money is how ledgers stop balancing.
        Log::critical('ChargeJob exhausted retries without a definitive PSP answer.', [
            'payment_intent_id' => $this->paymentIntentId,
            'exception' => $exception?->getMessage(),
        ]);
    }
}
