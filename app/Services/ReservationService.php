<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ProductKeyStatus;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductKey;
use App\Models\Reservation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Holds one concrete key for an order for a few minutes.
 *
 * A reservation is what makes the last-unit race honest: the key leaves the
 * storefront the moment an order is created, so a second buyer is told the item
 * is gone instead of paying for something that is no longer there. When the
 * timer runs out the key goes back on sale for everyone.
 */
class ReservationService
{
    /** Reservation TTL in minutes. */
    public const RESERVATION_TTL_MINUTES = 5;

    public function __construct(
        private readonly CatalogService $catalog,
    ) {}

    /**
     * Take one available key for this order and hold it.
     *
     * Returns null when the product has no key left — the caller turns that into
     * a "just sold out" answer rather than an error.
     */
    public function createReservation(Order $order, Product $product): ?Reservation
    {
        try {
            return DB::transaction(function () use ($order, $product) {
                // A retry for the same order reuses whatever it already holds.
                $existing = Reservation::where('order_id', $order->id)
                    ->whereNull('cancelled_at')
                    ->first();

                if ($existing) {
                    return $existing;
                }

                $key = $this->claimKey($product->id, $order->id);

                if (! $key) {
                    Log::info('reservation.sold_out', [
                        'order_id' => $order->id,
                        'product_id' => $product->id,
                    ]);

                    return null;
                }

                $reservation = Reservation::create([
                    'order_id' => $order->id,
                    'product_id' => $product->id,
                    'product_key_id' => $key->id,
                    'expires_at' => now()->addMinutes(self::RESERVATION_TTL_MINUTES),
                ]);

                Log::info('reservation.created', [
                    'reservation_id' => $reservation->id,
                    'order_id' => $order->id,
                    'product_key_id' => $key->id,
                    'expires_at' => $reservation->expires_at->toISOString(),
                ]);

                return $reservation;
            });
        } catch (QueryException) {
            // A concurrent request won the partial unique index. Whatever it
            // created is the truth for this order.
            return Reservation::where('order_id', $order->id)
                ->whereNull('cancelled_at')
                ->first();
        }
    }

    /**
     * Move one available key to `reserved` and take it off the storefront.
     *
     * Runs inside the caller's transaction so the counter can never drift away
     * from the key rows.
     */
    private function claimKey(int $productId, int $orderId): ?ProductKey
    {
        $query = ProductKey::where('product_id', $productId)
            ->where('status', ProductKeyStatus::Available)
            ->orderBy('id');

        // SQLite has no row-level locking; Postgres uses SKIP LOCKED so racing
        // buyers grab different keys instead of queueing behind one another.
        if (DB::getDriverName() === 'sqlite') {
            $query->lockForUpdate();
        } else {
            $query->lock('for update skip locked');
        }

        $key = $query->first();

        if (! $key) {
            return null;
        }

        $key->update([
            'status' => ProductKeyStatus::Reserved,
            'order_id' => $orderId,
        ]);

        $this->catalog->adjustAvailability($productId, -1);

        return $key;
    }

    /**
     * Is this order's hold still good?
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
            $this->releaseReservation($reservation);

            return false;
        }

        return true;
    }

    /**
     * Drop a reservation and put its key back on sale.
     *
     * The key only goes back if it is still `reserved` for this same order —
     * once delivery has issued it, there is nothing to release.
     */
    public function releaseReservation(Reservation $reservation): void
    {
        DB::transaction(function () use ($reservation) {
            $fresh = Reservation::whereKey($reservation->id)
                ->whereNull('cancelled_at')
                ->lockForUpdate()
                ->first();

            if (! $fresh) {
                return; // Someone else released it first.
            }

            $released = false;

            if ($fresh->product_key_id !== null) {
                $released = ProductKey::whereKey($fresh->product_key_id)
                    ->where('order_id', $fresh->order_id)
                    ->where('status', ProductKeyStatus::Reserved->value)
                    ->update([
                        'status' => ProductKeyStatus::Available->value,
                        'order_id' => null,
                    ]) === 1;
            }

            $fresh->update(['cancelled_at' => now()]);

            if ($released) {
                $this->catalog->adjustAvailability($fresh->product_id, 1);
            }

            Log::info('reservation.released', [
                'reservation_id' => $fresh->id,
                'order_id' => $fresh->order_id,
                'key_returned_to_stock' => $released,
            ]);
        });
    }

    /**
     * Sweep every reservation whose timer has run out. Returns how many were
     * released.
     */
    public function cancelExpiredReservations(): int
    {
        $expired = Reservation::whereNull('cancelled_at')
            ->where('expires_at', '<', now())
            ->get();

        foreach ($expired as $reservation) {
            $this->releaseReservation($reservation);
        }

        return $expired->count();
    }

    /**
     * Seconds left on the hold, 0 once it is gone.
     */
    public function getRemainingTime(Reservation $reservation): int
    {
        if (! $reservation->isActive()) {
            return 0;
        }

        // Order matters: Carbon 3 returns a signed difference, so the future
        // instant has to be the argument, not the receiver.
        return max(0, (int) now()->diffInSeconds($reservation->expires_at, false));
    }
}
