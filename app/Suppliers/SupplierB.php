<?php

declare(strict_types=1);

namespace App\Suppliers;

class SupplierB extends BaseSupplier
{
    public function name(): string
    {
        return 'supplier_b';
    }

    protected function configKey(): string
    {
        return 'b';
    }

    protected function codePrefix(): string
    {
        return 'SB';
    }
}
