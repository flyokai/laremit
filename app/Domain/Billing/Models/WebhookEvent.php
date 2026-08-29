<?php

declare(strict_types=1);

namespace App\Domain\Billing\Models;

use App\Domain\Billing\Enums\Store;
use App\Domain\Billing\Enums\WebhookEventStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

/**
 * One delivery from a provider, persisted raw before anything is done with
 * it. UNIQUE(provider, provider_event_id) is the edge's dedup floor;
 * `status` is what the edge branches on when deciding to dispatch.
 *
 * @property int $id
 * @property Store $provider
 * @property string $provider_event_id
 * @property string $type
 * @property string $payload
 * @property CarbonImmutable|null $provider_created_at
 * @property CarbonImmutable $received_at
 * @property WebhookEventStatus $status
 * @property string|null $outcome
 * @property int $attempts
 * @property string|null $last_error
 * @property CarbonImmutable|null $processed_at
 */
#[Fillable([
    'provider',
    'provider_event_id',
    'type',
    'payload',
    'provider_created_at',
    'received_at',
    'status',
    'outcome',
    'attempts',
    'last_error',
    'processed_at',
])]
final class WebhookEvent extends Model
{
    use MassPrunable;

    public $timestamps = false;

    /**
     * @return array<string, mixed>
     */
    public function decodedPayload(): array
    {
        $decoded = json_decode($this->payload, true, 32, JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Settled rows age out; pending ones never do — a pending row is
     * unfinished work, and the reaper owns it.
     *
     * @return Builder<WebhookEvent>
     */
    public function prunable(): Builder
    {
        return self::query()
            ->where('status', '!=', WebhookEventStatus::Pending->value)
            ->where('received_at', '<', CarbonImmutable::now()->subDays((int) config('billing.webhooks.retention_days')));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider' => Store::class,
            'status' => WebhookEventStatus::class,
            'attempts' => 'integer',
            'provider_created_at' => 'immutable_datetime',
            'received_at' => 'immutable_datetime',
            'processed_at' => 'immutable_datetime',
        ];
    }
}
