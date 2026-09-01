<?php

declare(strict_types=1);

namespace App\Enums;

enum LedgerAccount: string
{
    /** Money actually received from the payment system. */
    case Cash = 'cash';

    /**
     * Paid for, but not yet delivered — what we still owe the customer.
     * This balance must always equal the value of paid-but-undelivered orders.
     */
    case DeferredRevenue = 'deferred_revenue';

    /** Recognised once the code is actually handed over. */
    case Revenue = 'revenue';
}
