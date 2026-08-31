<?php

declare(strict_types=1);

namespace App\Domain\Billing\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * One operational counter for one UTC day — activations, cancellations,
 * payment outcomes, refunds — maintained exactly-once by ProjectBillingMetric.
 * Deliberately not money: revenue lives in the ledger; these are the counts
 * an on-call human (and, in Phase 9, a dashboard) reads at a glance.
 *
 * @property int $id
 * @property CarbonImmutable $metric_date
 * @property string $metric
 * @property int $value
 */
#[Fillable(['metric_date', 'metric', 'value'])]
final class BillingMetric extends Model
{
    public $timestamps = false;

    public static function valueFor(CarbonImmutable $day, string $metric): int
    {
        return (int) self::query()
            ->where('metric_date', $day->utc()->toDateString())
            ->where('metric', $metric)
            ->value('value');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metric_date' => 'immutable_date',
            'value' => 'integer',
        ];
    }
}
