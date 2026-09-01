<?php

declare(strict_types=1);

namespace App\DTO;

readonly class IssueRequest
{
    public function __construct(
        public string $requestId,
        public string $sku,
        public string $orderId,
    ) {}
}
