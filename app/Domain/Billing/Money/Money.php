<?php

declare(strict_types=1);

namespace App\Domain\Billing\Money;

use InvalidArgumentException;
use Stringable;

/**
 * Money: integer minor units plus an ISO-4217 code. No floats, ever — a
 * float touched this amount exactly zero times on its way here, and the
 * ledger's exactness depends on keeping it that way.
 *
 * Amounts are signed: ledger credit lines are negative by convention
 * (entries in a transaction sum to zero). Charge amounts are validated
 * positive where positivity is the rule, not here.
 */
final readonly class Money implements Stringable
{
    private function __construct(
        public int $minor,
        public string $currency,
    ) {}

    public static function of(int $minor, string $currency): self
    {
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new InvalidArgumentException("Currency [{$currency}] is not an uppercase ISO-4217 code.");
        }

        return new self($minor, $currency);
    }

    public function add(self $other): self
    {
        return new self($this->minor + $this->assertSameCurrency($other)->minor, $this->currency);
    }

    public function subtract(self $other): self
    {
        return new self($this->minor - $this->assertSameCurrency($other)->minor, $this->currency);
    }

    public function negate(): self
    {
        return new self(-$this->minor, $this->currency);
    }

    public function isZero(): bool
    {
        return $this->minor === 0;
    }

    public function isPositive(): bool
    {
        return $this->minor > 0;
    }

    public function isNegative(): bool
    {
        return $this->minor < 0;
    }

    public function equals(self $other): bool
    {
        return $this->minor === $other->minor && $this->currency === $other->currency;
    }

    private function assertSameCurrency(self $other): self
    {
        if ($other->currency !== $this->currency) {
            throw new InvalidArgumentException(
                "Refusing {$this->currency} arithmetic with {$other->currency}: mixed-currency math is a bug, not a conversion."
            );
        }

        return $other;
    }

    /** For logs and error messages only — never for arithmetic or storage. */
    public function __toString(): string
    {
        return sprintf('%s %d', $this->currency, $this->minor);
    }
}
