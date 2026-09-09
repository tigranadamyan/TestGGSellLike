<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Product;
use App\Models\Reservation;

class OrderService
{
    public function __construct(
        private readonly ReservationService $reservationService,
    ) {}

    public function createOrder(Product $product, ?string $idempotencyKey = null): Order
    {
        // Check for existing order with same idempotency key
        if ($idempotencyKey) {
            $existingOrder = Order::where('idempotency_key', $idempotencyKey)->first();
            if ($existingOrder) {
                return $existingOrder;
            }
        }

        $order = Order::create([
            'product_id' => $product->id,
            'sku' => $product->sku,
            'price' => $product->price,
            'currency' => $product->currency,
            'status' => OrderStatus::Created,
            'idempotency_key' => $idempotencyKey,
        ]);

        // Create reservation for this product
        $reservation = $this->reservationService->createReservation($order, $product);

        return $order;
    }

    public function getOrder(int $id): ?Order
    {
        return Order::with('product', 'delivery', 'reservation')->find($id);
    }

    /**
     * Get the reservation for an order, if any.
     */
    public function getReservation(Order $order): ?Reservation
    {
        return Reservation::where('order_id', $order->id)
            ->whereNull('cancelled_at')
            ->first();
    }
}
