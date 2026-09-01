<?php

declare(strict_types=1);

namespace App\Enums;

enum ProductKeyStatus: string
{
    case Available = 'available';
    case Reserved = 'reserved';
    case Issued = 'issued';
}
