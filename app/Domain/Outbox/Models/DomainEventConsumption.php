<?php

declare(strict_types=1);

namespace App\Domain\Outbox\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

/**
 * One consumer's proof that one event's effect is already in the database —
 * the "track processed event ids" half of exactly-once effects. The row is
 * written by ConsumeOnce in the SAME transaction as the effect, so the marker
 * cannot exist without the effect nor the effect without the marker.
 *
 * @property int $id
 * @property string $consumer
 * @property string $event_id
 * @property CarbonImmutable $processed_at
 */
#[Fillable(['consumer', 'event_id', 'processed_at'])]
final class DomainEventConsumption extends Model
{
    use MassPrunable;

    public $timestamps = false;

    /**
     * Markers age out once nothing can redeliver the event any more. The
     * retention must comfortably exceed every redelivery horizon — stream
     * retention, dead-letter replay — because a pruned marker is a reopened
     * dedup window (tech-debt #17).
     *
     * @return Builder<DomainEventConsumption>
     */
    public function prunable(): Builder
    {
        return self::query()
            ->where('processed_at', '<', CarbonImmutable::now()->subDays((int) config('outbox.consumptions_retention_days')));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'processed_at' => 'immutable_datetime',
        ];
    }
}
