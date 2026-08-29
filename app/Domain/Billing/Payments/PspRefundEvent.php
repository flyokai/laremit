<?php

declare(strict_types=1);

namespace App\Domain\Billing\Payments;

use App\Domain\Billing\Psp\RemoteCharge;
use App\Domain\Billing\Psp\RemoteRefund;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * A refund as reported by the PSP — from a charge.refunded webhook or from
 * reconciliation's charge listing. Refunds are FACTS, not state: the
 * refund id is the identity, and two partial refunds are two events even
 * when the charge looks identical afterwards.
 */
final readonly class PspRefundEvent
{
    public function __construct(
        public string $eventId,
        public string $refundId,
        public string $chargeId,
        public int $paymentIntentId,
        public int $amountMinor,
        public string $currency,
        public ?string $reason,
        public CarbonImmutable $occurredAt,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromWebhookPayload(array $payload): self
    {
        $eventId = $payload['event_id'] ?? null;
        $data = $payload['data'] ?? null;

        if (! is_string($eventId) || ($payload['type'] ?? null) !== 'charge.refunded' || ! is_array($data)) {
            throw new InvalidArgumentException('Refund webhook is missing event_id, the charge.refunded type, or data.');
        }

        $refundId = $data['refund_id'] ?? null;
        $chargeId = $data['charge_id'] ?? null;
        $amount = $data['amount_minor'] ?? null;
        $currency = $data['currency'] ?? null;
        $metadata = $data['metadata'] ?? null;

        if (! is_string($refundId) || ! is_string($chargeId) || ! is_int($amount) || ! is_string($currency) || ! is_array($metadata)) {
            throw new InvalidArgumentException('Refund data is missing refund_id, charge_id, amount_minor, currency, or metadata.');
        }

        $intentId = $metadata['payment_intent_id'] ?? null;

        if (! is_int($intentId)) {
            throw new InvalidArgumentException('Refund metadata does not reference a payment intent.');
        }

        $reason = $data['reason'] ?? null;
        $createdAt = $payload['created_at'] ?? null;

        return new self(
            $eventId,
            $refundId,
            $chargeId,
            $intentId,
            $amount,
            $currency,
            is_string($reason) ? $reason : null,
            is_string($createdAt) ? CarbonImmutable::parse($createdAt)->utc() : CarbonImmutable::now(),
        );
    }

    /** Reconciliation's road in: the same fact, read from the provider's records. */
    public static function fromRemote(RemoteCharge $charge, RemoteRefund $refund, int $paymentIntentId): self
    {
        return new self(
            "reconcile_{$refund->refundId}",
            $refund->refundId,
            $charge->chargeId,
            $paymentIntentId,
            $refund->amountMinor,
            $refund->currency,
            $refund->reason,
            $refund->createdAt,
        );
    }
}
