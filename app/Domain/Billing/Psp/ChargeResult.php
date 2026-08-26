<?php

declare(strict_types=1);

namespace App\Domain\Billing\Psp;

/**
 * A definitive PSP answer: the charge exists (succeeded or declined) and
 * has an id. Ambiguity is an exception on the client, never a result.
 */
final readonly class ChargeResult
{
    private function __construct(
        public string $chargeId,
        public bool $succeeded,
        public ?string $declineCode,
    ) {}

    public static function succeeded(string $chargeId): self
    {
        return new self($chargeId, true, null);
    }

    public static function declined(string $chargeId, string $declineCode): self
    {
        return new self($chargeId, false, $declineCode);
    }
}
