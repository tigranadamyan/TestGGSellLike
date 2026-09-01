<?php

declare(strict_types=1);

namespace App\Suppliers;

class SupplierA extends BaseSupplier
{
    public function name(): string
    {
        return 'supplier_a';
    }

    protected function configKey(): string
    {
        return 'a';
    }

    protected function codePrefix(): string
    {
        return 'SA';
    }
}
