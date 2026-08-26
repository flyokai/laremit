<?php

declare(strict_types=1);

namespace App\Domain\Billing\Money;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Casts a virtual `amount` attribute to Money, backed by the two real
 * columns (amount_minor, currency). One value object in code, two plain
 * integer/char columns in the database — the ledger never stores anything
 * a float has touched.
 *
 * @implements CastsAttributes<Money, Money>
 */
final class MoneyCast implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): Money
    {
        return Money::of((int) $attributes['amount_minor'], (string) $attributes['currency']);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{amount_minor: int, currency: string}
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        if (! $value instanceof Money) {
            throw new InvalidArgumentException('The amount attribute only accepts Money.');
        }

        return [
            'amount_minor' => $value->minor,
            'currency' => $value->currency,
        ];
    }
}
