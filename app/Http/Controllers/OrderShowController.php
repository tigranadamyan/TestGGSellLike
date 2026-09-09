<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Reservation;
use App\Services\ReservationService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OrderShowController extends Controller
{
    public function __construct(
        private readonly ReservationService $reservations,
    ) {}

    public function __invoke(int $id, Request $request): Response
    {
        $order = Order::with('product', 'delivery')->find($id);

        if (! $order) {
            abort(404);
        }

        $reservation = Reservation::where('order_id', $order->id)
            ->whereNull('cancelled_at')
            ->first();

        return Inertia::render('OrderShow', [
            'order' => [
                'id' => $order->id,
                'sku' => $order->sku,
                'price' => $order->price,
                'currency' => $order->currency,
                'status' => $order->status->value,
                'created_at' => $order->created_at->toISOString(),
                'delivery' => $order->delivery ? [
                    'status' => $order->delivery->status->value,
                    'code' => $order->delivery->code,
                    'supplier' => $order->delivery->supplier,
                ] : null,
            ],
            'reservation' => $reservation ? [
                'expires_at' => $reservation->expires_at->toISOString(),
                'remaining_seconds' => $this->reservations->getRemainingTime($reservation),
                'is_active' => $reservation->isActive(),
            ] : null,
        ]);
    }
}
