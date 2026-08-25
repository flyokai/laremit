<?php

declare(strict_types=1);

namespace App\Domain\Events\Support;

use App\Domain\Events\Enums\Priority;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use JsonException;
use Throwable;

/**
 * A validated event envelope — the only shape that ever enters the stream.
 *
 * occurred_at is the producer's clock and is untrusted beyond the bounds the
 * validator enforced; received_at is ours, stamped once at ingest and stable
 * across redeliveries so that every consumer sees the same value.
 */
final readonly class Envelope
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $eventId,
        public string $type,
        public int $schemaVersion,
        public CarbonImmutable $occurredAt,
        public ?int $userId,
        public string $product,
        public Priority $priority,
        public array $payload,
        public CarbonImmutable $receivedAt,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function withSchema(int $schemaVersion, array $payload): self
    {
        return new self(
            $this->eventId,
            $this->type,
            $schemaVersion,
            $this->occurredAt,
            $this->userId,
            $this->product,
            $this->priority,
            $payload,
            $this->receivedAt,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'event_id' => $this->eventId,
            'type' => $this->type,
            'schema_version' => $this->schemaVersion,
            'occurred_at' => $this->occurredAt->toISOString(),
            'user_id' => $this->userId,
            'product' => $this->product,
            'priority' => $this->priority->value,
            'payload' => $this->payload,
            'received_at' => $this->receivedAt->toISOString(),
        ];
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Rehydrate a stream entry. Throws on anything malformed — the consumer
     * loop turns that into a dead-letter, never a crash.
     *
     * @throws InvalidArgumentException
     */
    public static function fromJson(string $json): self
    {
        try {
            $data = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidArgumentException("Envelope is not valid JSON: {$e->getMessage()}", previous: $e);
        }

        if (! is_array($data)) {
            throw new InvalidArgumentException('Envelope must decode to an object.');
        }

        foreach (['event_id', 'type', 'product', 'occurred_at', 'received_at', 'priority'] as $key) {
            if (! is_string($data[$key] ?? null)) {
                throw new InvalidArgumentException("Envelope field [{$key}] is missing or not a string.");
            }
        }

        if (! is_int($data['schema_version'] ?? null)) {
            throw new InvalidArgumentException('Envelope field [schema_version] is missing or not an integer.');
        }

        $priority = Priority::tryFrom($data['priority']);

        if ($priority === null) {
            throw new InvalidArgumentException("Envelope field [priority] has unknown value [{$data['priority']}].");
        }

        $userId = $data['user_id'] ?? null;

        if ($userId !== null && ! is_int($userId)) {
            throw new InvalidArgumentException('Envelope field [user_id] must be an integer or null.');
        }

        $payload = $data['payload'] ?? [];

        if (! is_array($payload)) {
            throw new InvalidArgumentException('Envelope field [payload] must be an object.');
        }

        try {
            $occurredAt = CarbonImmutable::parse($data['occurred_at'])->utc();
            $receivedAt = CarbonImmutable::parse($data['received_at'])->utc();
        } catch (Throwable $e) {
            throw new InvalidArgumentException("Envelope timestamps are unparseable: {$e->getMessage()}", previous: $e);
        }

        return new self(
            $data['event_id'],
            $data['type'],
            $data['schema_version'],
            $occurredAt,
            $userId,
            $data['product'],
            $priority,
            $payload,
            $receivedAt,
        );
    }
}
