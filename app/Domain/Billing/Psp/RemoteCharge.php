<?php

declare(strict_types=1);

namespace App\Domain\Billing\Psp;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * A charge as the PSP's records describe it — what reconciliation compares
 * our intent against. Carries OUR idempotency key back, which is the join:
 * it is the only identifier both sides were guaranteed to know before the
 * money moved.
 */
final readonly class RemoteCharge
{
    /**
     * @param  list<RemoteRefund>  $refunds
     */
    public function __construct(
        public string $chargeId,
        public string $idempotencyKey,
        public ?int $paymentIntentId,
        public int $amountMinor,
        public string $currency,
        public bool $succeeded,
        public ?string $declineCode,
        public CarbonImmutable $createdAt,
        public array $refunds,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $chargeId = $data['charge_id'] ?? null;
        $key = $data['idempotency_key'] ?? null;
        $amount = $data['amount_minor'] ?? null;
        $currency = $data['currency'] ?? null;
        $status = $data['status'] ?? null;
        $createdAt = $data['created_at'] ?? null;

        if (! is_string($chargeId) || ! is_string($key) || ! is_int($amount) || ! is_string($currency)
            || ! in_array($status, ['succeeded', 'failed'], true) || ! is_string($createdAt)) {
            throw new InvalidArgumentException('Remote charge is missing a required field.');
        }

        $metadata = is_array($data['metadata'] ?? null) ? $data['metadata'] : [];
        $intentId = $metadata['payment_intent_id'] ?? null;
        $declineCode = $data['decline_code'] ?? null;

        $refunds = [];

        foreach (is_array($data['refunds'] ?? null) ? $data['refunds'] : [] as $refund) {
            if (is_array($refund)) {
                $refunds[] = RemoteRefund::fromArray($refund);
            }
        }

        return new self(
            $chargeId,
            $key,
            is_int($intentId) ? $intentId : null,
            $amount,
            $currency,
            $status === 'succeeded',
            is_string($declineCode) ? $declineCode : null,
            CarbonImmutable::parse($createdAt)->utc(),
            $refunds,
        );
    }

    public function refundedMinor(): int
    {
        return array_sum(array_map(static fn (RemoteRefund $refund): int => $refund->amountMinor, $this->refunds));
    }
}
