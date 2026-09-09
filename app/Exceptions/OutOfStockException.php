<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Raised when an order cannot be created because the last key was taken by
 * someone else a moment earlier. Rendered as a plain 409, never a 500.
 */
class OutOfStockException extends RuntimeException
{
    public function __construct(
        public readonly string $sku,
    ) {
        parent::__construct("No keys left for {$sku}.");
    }
}
