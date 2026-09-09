<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Order;
use App\Models\Product;
use App\Models\Reservation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ReservationService
{
    /** Reservation TTL in minutes */
    public const RESERVATION_TTL_MINUTES = 5;

    /**
     * Create a reservation for a product when an order is created.
     *
     * Returns the reservation if successful, null if product is already reserved.
     */
    public function createReservation(Order $order, Product $product): ?Reservation
    {
        return DB::transaction(function () use ($order, $product) {
            // Check if there's an active reservation for this product
            $existingReservation = Reservation::where('product_id', $product->id)
                ->whereNull('cancelled_at')
                ->where('expires_at', '>', now())
                ->first();

            if ($existingReservation) {
                Log::warning('reservation.conflict', [
                    'product_id' => $product->id,
                    'existing_order_id' => $existingReservation->order_id,
                    'new_order_id' => $order->id,
                ]);

                return null;
            }

            // Create new reservation
            $reservation = Reservation::create([
                'order_id' => $order->id,
                'product_id' => $product->id,
                'expires_at' => now()->addMinutes(self::RESERVATION_TTL_MINUTES),
            ]);

            Log::info('reservation.created', [
                'reservation_id' => $reservation->id,
                'order_id' => $order->id,
                'product_id' => $product->id,
                'expires_at' => $reservation->expires_at->toISOString(),
            ]);

            return $reservation;
        });
    }

    /**
     * Validate that a reservation is still active for an order.
     *
     * Returns true if reservation is valid, false if expired or cancelled.
     */
    public function validateReservation(Order $order): bool
    {
        $reservation = Reservation::where('order_id', $order->id)
            ->whereNull('cancelled_at')
            ->first();

        if (! $reservation) {
            return false;
        }

        if ($reservation->isExpired()) {
            $this->cancelReservation($reservation);

            return false;
        }

        return true;
    }

    /**
     * Cancel a reservation and release the product.
     */
    public function cancelReservation(Reservation $reservation): void
    {
        $reservation->cancel();

        Log::info('reservation.cancelled', [
            'reservation_id' => $reservation->id,
            'order_id' => $reservation->order_id,
            'product_id' => $reservation->product_id,
        ]);
    }

    /**
     * Cancel all expired reservations.
     *
     * Returns the number of cancelled reservations.
     */
    public function cancelExpiredReservations(): int
    {
        $expiredReservations = Reservation::whereNull('cancelled_at')
            ->where('expires_at', '<', now())
            ->get();

        $count = 0;

        foreach ($expiredReservations as $reservation) {
            $this->cancelReservation($reservation);
            $count++;
        }

        return $count;
    }

    /**
     * Get the remaining time for a reservation in seconds.
     *
     * Returns 0 if reservation is expired or cancelled.
     */
    public function getRemainingTime(Reservation $reservation): int
    {
        if (! $reservation->isActive()) {
            return 0;
        }

        return max(0, $reservation->expires_at->diffInSeconds(now()));
    }
}
