<?php

declare(strict_types=1);

namespace App\Domain\Billing\Payments;

use App\Domain\Billing\Psp\RemoteCharge;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * A charge outcome as reported by the PSP — from a webhook or normalized
 * from a synchronous API response. One shape, so ApplyChargeOutcome cannot
 * care which path delivered it.
 */
final readonly class PspEvent
{
    public function __construct(
        public string $eventId,
        public string $type, // charge.succeeded | charge.failed
        public string $chargeId,
        public int $paymentIntentId,
        public int $amountMinor,
        public string $currency,
        public ?string $declineCode,
        public CarbonImmutable $occurredAt,
    ) {}

    public function succeeded(): bool
    {
        return $this->type === 'charge.succeeded';
    }

    /**
     * Reconciliation's road in: the provider's own record of the charge,
     * normalized into the shape the funnel already understands.
     */
    public static function fromRemote(RemoteCharge $charge, int $paymentIntentId): self
    {
        return new self(
            "reconcile_{$charge->chargeId}",
            $charge->succeeded ? 'charge.succeeded' : 'charge.failed',
            $charge->chargeId,
            $paymentIntentId,
            $charge->amountMinor,
            $charge->currency,
            $charge->declineCode,
            $charge->createdAt,
        );
    }

    /**
     * Parse a webhook payload. Throws on malformed input — the caller
     * decides whether that means 400 or log-and-drop.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function fromWebhookPayload(array $payload): self
    {
        $eventId = $payload['event_id'] ?? null;
        $type = $payload['type'] ?? null;
        $data = $payload['data'] ?? null;

        if (! is_string($eventId) || ! is_array($data) || ! in_array($type, ['charge.succeeded', 'charge.failed'], true)) {
            throw new InvalidArgumentException('Webhook payload is missing event_id, a known type, or data.');
        }

        $chargeId = $data['charge_id'] ?? null;
        $amountMinor = $data['amount_minor'] ?? null;
        $currency = $data['currency'] ?? null;
        $metadata = $data['metadata'] ?? null;

        if (! is_string($chargeId) || ! is_int($amountMinor) || ! is_string($currency) || ! is_array($metadata)) {
            throw new InvalidArgumentException('Webhook data is missing charge_id, amount_minor, currency, or metadata.');
        }

        $intentId = $metadata['payment_intent_id'] ?? null;

        if (! is_int($intentId)) {
            throw new InvalidArgumentException('Webhook metadata does not reference a payment intent.');
        }

        $declineCode = $data['decline_code'] ?? null;
        $createdAt = $payload['created_at'] ?? null;

        return new self(
            $eventId,
            $type,
            $chargeId,
            $intentId,
            $amountMinor,
            $currency,
            is_string($declineCode) ? $declineCode : null,
            is_string($createdAt) ? CarbonImmutable::parse($createdAt)->utc() : CarbonImmutable::now(),
        );
    }
}
