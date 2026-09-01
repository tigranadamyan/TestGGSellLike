<?php

declare(strict_types=1);

namespace App\Enums;

enum PaymentEventStatus: string
{
    case Paid = 'paid';
    case Failed = 'failed';
}
