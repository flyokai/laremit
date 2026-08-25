<?php

declare(strict_types=1);

namespace App\Domain\Events\Models;

use App\Domain\Events\Enums\Priority;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

/**
 * Append-only event archive; the long-term log the whole pipeline can be
 * replayed from. Rows are written by the archive consumer via insertOrIgnore
 * — never through Eloquent save(), which is why timestamps are off.
 *
 * On MySQL the table is partitioned by month of received_at and retention is
 * a partition drop (see `events:partitions`). MassPrunable is the portable
 * fallback: on a partitioned table `model:prune` finds little left to do.
 *
 * @property int $id
 * @property string $event_id
 * @property int|null $user_id
 * @property string $product
 * @property string $type
 * @property int $schema_version
 * @property Priority $priority
 * @property array<string, mixed> $payload
 * @property CarbonImmutable $occurred_at
 * @property CarbonImmutable $received_at
 * @property CarbonImmutable|null $created_at
 */
final class ArchivedEvent extends Model
{
    use MassPrunable;

    protected $table = 'events_archive';

    public $timestamps = false;

    /**
     * @return Builder<ArchivedEvent>
     */
    public function prunable(): Builder
    {
        return self::query()->where(
            'received_at',
            '<',
            CarbonImmutable::now()->subMonths((int) config('events.archive.retention_months')),
        );
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'schema_version' => 'integer',
            'priority' => Priority::class,
            'payload' => 'array',
            'occurred_at' => 'immutable_datetime',
            'received_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
