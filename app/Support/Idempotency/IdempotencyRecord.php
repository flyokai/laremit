<?php

declare(strict_types=1);

namespace App\Support\Idempotency;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

/**
 * One claimed inbound idempotency key (ADR-004, layer 1). Rows past the
 * retention window are pruned; a key reused after that is a new request by
 * definition, and that contract is documented on the API.
 *
 * @property int $id
 * @property string $key
 * @property int|null $user_id
 * @property string $request_hash
 * @property string $status
 * @property int|null $response_status
 * @property string|null $response_body
 * @property CarbonImmutable $locked_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['key', 'user_id', 'request_hash', 'status', 'response_status', 'response_body', 'locked_at'])]
final class IdempotencyRecord extends Model
{
    use MassPrunable;

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    /**
     * @return Builder<IdempotencyRecord>
     */
    public function prunable(): Builder
    {
        return self::query()->where(
            'created_at',
            '<',
            CarbonImmutable::now()->subHours((int) config('billing.idempotency.retention_hours')),
        );
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'response_status' => 'integer',
            'locked_at' => 'immutable_datetime',
        ];
    }
}
