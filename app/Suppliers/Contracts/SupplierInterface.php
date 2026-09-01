<?php

declare(strict_types=1);

namespace App\Suppliers\Contracts;

use App\DTO\IssueRequest;
use App\DTO\IssueResult;

interface SupplierInterface
{
    public function name(): string;

    public function issue(IssueRequest $request): IssueResult;
}
