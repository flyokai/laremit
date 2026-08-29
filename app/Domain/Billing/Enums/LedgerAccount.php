<?php

declare(strict_types=1);

namespace App\Domain\Billing\Enums;

/**
 * The chart of accounts, deliberately tiny. Every ledger transaction is a
 * set of lines across these accounts summing to zero; a successful charge
 * is +amount psp_cash / -amount revenue, and a refund reverses the cash
 * side into a contra-revenue account so gross revenue stays readable.
 */
enum LedgerAccount: string
{
    /** Money the PSP holds on our behalf. */
    case PspCash = 'psp_cash';

    /** Earned subscription revenue (credit-normal: negative lines). */
    case Revenue = 'revenue';

    /** Money given back (debit-normal: positive lines). Contra to revenue. */
    case Refunds = 'refunds';
}
