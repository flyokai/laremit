<?php

declare(strict_types=1);

namespace App\Domain\Billing\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * The durable record of one reconciliation run — the mismatch metric as
 * data rather than as a log line somebody has to grep for.
 *
 * @property int $id
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable $finished_at
 * @property CarbonImmutable $window_start
 * @property array<string, int> $scanned
 * @property array<string, int> $findings
 * @property int $fixed
 * @property int $unresolved
 * @property int $duration_ms
 */
#[Fillable(['started_at', 'finished_at', 'window_start', 'scanned', 'findings', 'fixed', 'unresolved', 'duration_ms'])]
final class ReconciliationRun extends Model
{
    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'window_start' => 'immutable_datetime',
            'scanned' => 'array',
            'findings' => 'array',
            'fixed' => 'integer',
            'unresolved' => 'integer',
            'duration_ms' => 'integer',
        ];
    }
}
