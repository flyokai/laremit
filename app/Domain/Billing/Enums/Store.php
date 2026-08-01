<?php

declare(strict_types=1);

namespace App\Domain\Billing\Enums;

/**
 * Where a subscription's money and lifecycle actually live.
 *
 * This matters because the source of truth differs: for Psp we own the
 * subscription state, while for Apple and Google the store owns it and we hold
 * a projection that reconciliation re-syncs (ADR-005, Phase 4).
 */
enum Store: string
{
    case Psp = 'psp';
    case Apple = 'apple';
    case Google = 'google';

    /** In-app purchases are authoritative at the store, not here. */
    public function isStoreAuthoritative(): bool
    {
        return $this !== self::Psp;
    }
}
