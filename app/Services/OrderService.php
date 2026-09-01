<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Product;

class OrderService
{
    public function createOrder(Product $product): Order
    {
        return Order::create([
            'product_id' => $product->id,
            'sku' => $product->sku,
            'price' => $product->price,
            'currency' => $product->currency,
            'status' => OrderStatus::Created,
        ]);
    }

    public function getOrder(int $id): ?Order
    {
        return Order::with('product', 'delivery')->find($id);
    }
}
