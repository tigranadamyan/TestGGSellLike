<?php

declare(strict_types=1);

namespace App\Enums;

enum LedgerEntryType: string
{
    case PaymentReceived = 'payment_received';
    case RevenueRecognised = 'revenue_recognised';
    case Refund = 'refund';
}
