<?php

declare(strict_types=1);

namespace App\Suppliers;

use App\Suppliers\Contracts\SupplierInterface;
use Illuminate\Support\Manager;

class SupplierManager extends Manager
{
    public function getDefaultDriver(): string
    {
        return 'a';
    }

    // Laravel's Manager resolves driver "a" via createADriver(); naming these
    // createSupplierA()/createSupplierB() made driver('a') throw at runtime.
    public function createADriver(): SupplierInterface
    {
        return new SupplierA;
    }

    public function createBDriver(): SupplierInterface
    {
        return new SupplierB;
    }
}
