<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Product;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast when a product's price or availability changes.
 * All connected clients receive the update in real-time.
 */
class CatalogUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  array{
     *     sku: string,
     *     name: string,
     *     type: string,
     *     price: string,
     *     currency: string,
     *     in_stock: bool,
     *     available: int,
     *     old_price?: string|null,
     *     old_available?: int|null,
     * }  $product
     */
    public function __construct(
        public readonly array $product,
        public readonly string $changeType = 'availability',
    ) {}

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'catalog.updated';
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return Channel<int>
     */
    public function broadcastOn(): Channel
    {
        return new Channel('catalog');
    }

    /**
     * Get the data to broadcast.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'product' => $this->product,
            'change_type' => $this->changeType,
            'timestamp' => now()->toISOString(),
        ];
    }
}
