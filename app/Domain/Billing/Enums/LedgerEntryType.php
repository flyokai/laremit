<?php

declare(strict_types=1);

namespace App\Domain\Billing\Enums;

enum LedgerEntryType: string
{
    case Charge = 'charge';
    case Refund = 'refund';
    case Adjustment = 'adjustment';
}
