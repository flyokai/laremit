<?php

declare(strict_types=1);

namespace App\Domain\Billing\Jobs;

use App\Domain\Billing\Payments\ApplyChargeOutcome;
use App\Domain\Billing\Payments\PspEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Apply one verified PSP webhook. The endpoint answered 200 already; from
 * here every delivery — duplicate, late, out of order — funnels into
 * ApplyChargeOutcome, whose decision table is where those cases go to die
 * quietly.
 */
final class ProcessPspWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [5, 30];

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(public array $payload) {}

    public function handle(ApplyChargeOutcome $outcomes): void
    {
        try {
            $event = PspEvent::fromWebhookPayload($this->payload);
        } catch (InvalidArgumentException $e) {
            // Malformed after signature verification = the PSP (mock) has a
            // bug. Retrying re-parses the same bytes; drop and say so.
            Log::error('Discarding malformed PSP webhook.', [
                'reason' => $e->getMessage(),
                'event_id' => $this->payload['event_id'] ?? null,
            ]);

            return;
        }

        $outcomes->apply($event);
    }
}
