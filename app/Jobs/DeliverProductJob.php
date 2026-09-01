<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Order;
use App\Services\DeliveryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class DeliverProductJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $backoff = 30;

    public int $maxExceptions = 3;

    public function __construct(
        public readonly int $orderId,
    ) {}

    public function handle(DeliveryService $deliveryService): void
    {
        $order = Order::find($this->orderId);

        if (! $order) {
            return;
        }

        $deliveryService->deliver($order);
    }
}
