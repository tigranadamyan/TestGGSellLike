<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\OrderStatus;
use App\Exceptions\OutOfStockException;
use App\Models\Order;
use App\Models\Product;
use App\Models\Reservation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class OrderService
{
    public function __construct(
        private readonly ReservationService $reservationService,
    ) {}

    /**
     * Create an order and hold a key for it.
     *
     * The storefront always requires stock: refusing up front is what keeps the
     * loser of a last-unit race from paying for something that is gone. Back
     * office flows may pass `$requireStock: false` to place a backorder, which
     * parks in `out_of_stock` after payment and is picked up by reconciliation
     * once a key appears or a supplier answers.
     *
     * @throws OutOfStockException when stock is required and the product sold out.
     */
    public function createOrder(Product $product, ?string $idempotencyKey = null, bool $requireStock = true): Order
    {
        if ($idempotencyKey !== null) {
            $existing = Order::where('idempotency_key', $idempotencyKey)->first();

            if ($existing) {
                return $existing;
            }
        }

        try {
            return DB::transaction(function () use ($product, $idempotencyKey, $requireStock) {
                $order = Order::create([
                    'product_id' => $product->id,
                    'sku' => $product->sku,
                    'price' => $product->price,
                    'currency' => $product->currency,
                    'status' => OrderStatus::Created,
                    'idempotency_key' => $idempotencyKey,
                ]);

                // No key left: roll the order back so a sold-out attempt never
                // leaves a payable order behind.
                if (! $this->reservationService->createReservation($order, $product) && $requireStock) {
                    throw new OutOfStockException($product->sku);
                }

                return $order;
            });
        } catch (QueryException $e) {
            // Two requests raced on the same idempotency key; the loser reads
            // back what the winner wrote instead of surfacing a 500.
            if ($idempotencyKey !== null) {
                $existing = Order::where('idempotency_key', $idempotencyKey)->first();

                if ($existing) {
                    return $existing;
                }
            }

            throw $e;
        }
    }

    public function getOrder(int $id): ?Order
    {
        return Order::with('product', 'delivery', 'reservation')->find($id);
    }

    /**
     * The order's live hold, if it still has one.
     */
    public function getReservation(Order $order): ?Reservation
    {
        return Reservation::where('order_id', $order->id)
            ->whereNull('cancelled_at')
            ->first();
    }

    public function remainingReservationSeconds(Reservation $reservation): int
    {
        return $this->reservationService->getRemainingTime($reservation);
    }
}
