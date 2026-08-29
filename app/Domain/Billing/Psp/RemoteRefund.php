<?php

declare(strict_types=1);

namespace App\Domain\Billing\Psp;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/** One refund as the PSP reports it. A fact with its own id, never a delta on the charge. */
final readonly class RemoteRefund
{
    public function __construct(
        public string $refundId,
        public int $amountMinor,
        public string $currency,
        public ?string $reason,
        public CarbonImmutable $createdAt,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $refundId = $data['refund_id'] ?? null;
        $amount = $data['amount_minor'] ?? null;
        $currency = $data['currency'] ?? null;
        $createdAt = $data['created_at'] ?? null;

        if (! is_string($refundId) || ! is_int($amount) || ! is_string($currency) || ! is_string($createdAt)) {
            throw new InvalidArgumentException('Remote refund is missing refund_id, amount_minor, currency or created_at.');
        }

        $reason = $data['reason'] ?? null;

        return new self($refundId, $amount, $currency, is_string($reason) ? $reason : null, CarbonImmutable::parse($createdAt)->utc());
    }
}
