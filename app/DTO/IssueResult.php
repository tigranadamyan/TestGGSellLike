<?php

declare(strict_types=1);

namespace App\DTO;

readonly class IssueResult
{
    private function __construct(
        public bool $success,
        public ?string $code = null,
        public ?string $error = null,
        public bool $isTimeout = false,
        public bool $isOutOfStock = false,
    ) {}

    public static function success(string $code): self
    {
        return new self(success: true, code: $code);
    }

    public static function failure(string $error): self
    {
        return new self(success: false, error: $error);
    }

    public static function timeout(): self
    {
        return new self(success: false, error: 'timeout', isTimeout: true);
    }

    /** Supplier answered definitively: nothing left to sell. Recoverable, not a crash. */
    public static function outOfStock(): self
    {
        return new self(success: false, error: 'out_of_stock', isOutOfStock: true);
    }
}
