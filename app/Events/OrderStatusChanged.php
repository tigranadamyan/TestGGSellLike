<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Order;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast when an order's status changes.
 * Used by frontend to show real-time order progress.
 */
class OrderStatusChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * The status change is written inside a transaction, and the delivery row is
     * written just before it. Broadcasting only after the commit means the worker
     * cannot read a half-written order and publish an order without its key.
     */
    public bool $afterCommit = true;

    public function __construct(
        public readonly Order $order,
        public readonly string $oldStatus,
        public readonly string $newStatus,
    ) {}

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'order.status_changed';
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return Channel<int>
     */
    public function broadcastOn(): Channel
    {
        // Private channel for this specific order
        return new Channel('order.'.$this->order->id);
    }

    /**
     * Get the data to broadcast.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        // The key travels with the status. Without it the order page flipped to
        // "delivered" but kept showing "waiting for the key" until a reload.
        $delivery = $this->order->delivery()->first();

        return [
            'order_id' => $this->order->id,
            'sku' => $this->order->sku,
            'old_status' => $this->oldStatus,
            'new_status' => $this->newStatus,
            'delivery' => $delivery ? [
                'status' => $delivery->status->value,
                'code' => $delivery->code,
                'supplier' => $delivery->supplier,
            ] : null,
            'timestamp' => now()->toISOString(),
        ];
    }
}
