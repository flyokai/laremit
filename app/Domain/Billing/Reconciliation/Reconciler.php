<?php

declare(strict_types=1);

namespace App\Domain\Billing\Reconciliation;

use App\Domain\Billing\Models\ReconciliationRun;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * "Webhooks are an optimization; reconciliation is the source of truth."
 *
 * Runs the sweeps in order, persists the tally, and alerts on the COUNT
 * of what it could not fix — never per finding, because a provider
 * outage that pages forty thousand times is a second incident. What it
 * did fix is logged as a warning: a reconciliation that quietly repairs
 * drift every hour is hiding a broken webhook path.
 *
 * @param  list<Sweep>  $sweeps
 */
final readonly class Reconciler
{
    /**
     * @param  list<Sweep>  $sweeps
     */
    public function __construct(private array $sweeps) {}

    public function run(?CarbonImmutable $now = null, ?int $windowHours = null): ReconciliationRun
    {
        $now ??= CarbonImmutable::now();
        $startedAt = CarbonImmutable::now();
        $windowStart = $now->subHours($windowHours ?? (int) config('billing.reconciliation.window_hours'));

        $report = new ReconciliationReport;

        foreach ($this->sweeps as $sweep) {
            $sweep->sweep($report, $now, $windowStart);
        }

        $finishedAt = CarbonImmutable::now();

        $run = ReconciliationRun::query()->create([
            'started_at' => $startedAt,
            'finished_at' => $finishedAt,
            'window_start' => $windowStart,
            'scanned' => $report->scanned,
            'findings' => $report->findings,
            'fixed' => $report->fixed,
            'unresolved' => $report->unresolved,
            'duration_ms' => (int) $startedAt->diffInMilliseconds($finishedAt),
        ]);

        $context = [
            'reconciliation_run_id' => $run->id,
            'scanned' => $report->scanned,
            'findings' => $report->findings,
            'fixed' => $report->fixed,
            'unresolved' => $report->unresolved,
        ];

        if ($report->unresolved > 0) {
            // The only thing here that should page: money or entitlement we
            // and the provider disagree about, with no safe automatic fix.
            Log::critical("Reconciliation left {$report->unresolved} discrepanc(ies) unresolved.", $context);
        } elseif ($report->fixed > 0) {
            Log::warning("Reconciliation repaired {$report->fixed} discrepanc(ies) — the webhook path is dropping deliveries.", $context);
        } else {
            Log::info('Reconciliation found no discrepancies.', $context);
        }

        return $run;
    }
}
