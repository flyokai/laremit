<?php

declare(strict_types=1);

namespace App\Domain\Events\Support;

use App\Domain\Events\Enums\Priority;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Envelope-only validation, hand-rolled on purpose.
 *
 * This runs up to 500 times per request on the hot path; the framework
 * Validator would spend more time building rule objects than checking them.
 * Payload contents are deliberately not inspected here — ingest validates the
 * envelope, downstream consumers own payload semantics.
 */
final readonly class EnvelopeValidator
{
    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    private const TYPE_PATTERN = '/^[a-z0-9]+(?:[._-][a-z0-9]+)*$/';

    private const PRODUCT_PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

    /** Must look like a timestamp before we let a date parser guess at it. */
    private const TIMESTAMP_PATTERN = '/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}/';

    /** Producer clocks earlier than this are broken, not early. */
    private const OCCURRED_AT_FLOOR = '2020-01-01T00:00:00Z';

    /** Tolerated forward clock skew. */
    private const FUTURE_SKEW_SECONDS = 3600;

    public function __construct(private SchemaRegistry $schemas) {}

    /**
     * @return array<string, string> field => problem; empty means valid
     */
    public function errors(mixed $raw, CarbonImmutable $receivedAt): array
    {
        if (! is_array($raw)) {
            return ['event' => 'must be an object'];
        }

        $errors = [];

        $eventId = $raw['event_id'] ?? null;

        if (! is_string($eventId) || preg_match(self::UUID_PATTERN, $eventId) !== 1) {
            $errors['event_id'] = 'must be a UUID string';
        }

        $type = $raw['type'] ?? null;

        if (! is_string($type) || $type === '' || strlen($type) > 128 || preg_match(self::TYPE_PATTERN, $type) !== 1) {
            $errors['type'] = 'must be a dot-separated lowercase identifier of at most 128 characters';
        }

        $version = $raw['schema_version'] ?? null;

        if (! is_int($version)) {
            $errors['schema_version'] = 'must be an integer';
        } elseif (! $this->schemas->isLive($version)) {
            $errors['schema_version'] = sprintf('is not a live version (live: %s)', implode(', ', $this->schemas->live()));
        }

        $product = $raw['product'] ?? null;

        if (! is_string($product) || $product === '' || strlen($product) > 64 || preg_match(self::PRODUCT_PATTERN, $product) !== 1) {
            $errors['product'] = 'must be a lowercase slug of at most 64 characters';
        }

        if (($occurredAtError = $this->occurredAtError($raw['occurred_at'] ?? null, $receivedAt)) !== null) {
            $errors['occurred_at'] = $occurredAtError;
        }

        $userId = $raw['user_id'] ?? null;

        if ($userId !== null && (! is_int($userId) || $userId < 1)) {
            $errors['user_id'] = 'must be a positive integer or null';
        }

        if (isset($raw['payload']) && ! is_array($raw['payload'])) {
            $errors['payload'] = 'must be an object';
        }

        $priority = $raw['priority'] ?? null;

        if ($priority !== null && (! is_string($priority) || Priority::tryFrom($priority) === null)) {
            $errors['priority'] = 'must be one of: operational, analytics';
        }

        return $errors;
    }

    /**
     * Build the envelope from input that errors() already passed.
     *
     * @param  array<string, mixed>  $raw
     */
    public function toEnvelope(array $raw, CarbonImmutable $receivedAt): Envelope
    {
        /** @var string $eventId */
        $eventId = $raw['event_id'];
        /** @var string $type */
        $type = $raw['type'];
        /** @var int $version */
        $version = $raw['schema_version'];
        /** @var string $product */
        $product = $raw['product'];
        /** @var string $occurredAt */
        $occurredAt = $raw['occurred_at'];
        /** @var int|null $userId */
        $userId = $raw['user_id'] ?? null;
        /** @var array<string, mixed> $payload */
        $payload = is_array($raw['payload'] ?? null) ? $raw['payload'] : [];
        $priority = is_string($raw['priority'] ?? null) ? Priority::from($raw['priority']) : Priority::Analytics;

        return new Envelope(
            strtolower($eventId),
            $type,
            $version,
            CarbonImmutable::parse($occurredAt)->utc(),
            $userId,
            $product,
            $priority,
            $payload,
            $receivedAt,
        );
    }

    private function occurredAtError(mixed $value, CarbonImmutable $receivedAt): ?string
    {
        if (! is_string($value) || strlen($value) > 40 || preg_match(self::TIMESTAMP_PATTERN, $value) !== 1) {
            return 'must be an ISO-8601 timestamp';
        }

        try {
            $occurredAt = CarbonImmutable::parse($value)->utc();
        } catch (Throwable) {
            return 'must be an ISO-8601 timestamp';
        }

        if ($occurredAt->lessThan(CarbonImmutable::parse(self::OCCURRED_AT_FLOOR))) {
            return 'is implausibly old';
        }

        if ($occurredAt->greaterThan($receivedAt->addSeconds(self::FUTURE_SKEW_SECONDS))) {
            return 'is in the future beyond tolerated clock skew';
        }

        return null;
    }
}
