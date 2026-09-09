<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\ProductKeyStatus;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductKey;
use App\Events\OrderStatusChanged;
use App\Models\Reservation;
use App\Services\ReservationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReservationTest extends TestCase
{
    use RefreshDatabase;

    private function productWithKeys(int $keys, string $sku = 'RESERVE_TEST'): Product
    {
        $product = Product::factory()->create([
            'sku' => $sku,
            'available_keys_count' => $keys,
        ]);

        for ($i = 0; $i < $keys; $i++) {
            ProductKey::create([
                'product_id' => $product->id,
                'code' => $sku.'-KEY-'.$i,
                'status' => ProductKeyStatus::Available,
            ]);
        }

        return $product;
    }

    public function test_creating_an_order_holds_one_concrete_key(): void
    {
        $product = $this->productWithKeys(3);

        $response = $this->postJson('/api/orders', ['sku' => $product->sku]);

        $response->assertCreated();

        $reservation = Reservation::where('order_id', $response->json('data.id'))->firstOrFail();

        $this->assertNotNull($reservation->product_key_id, 'The hold must point at a key.');
        $this->assertSame(
            ProductKeyStatus::Reserved,
            ProductKey::findOrFail($reservation->product_key_id)->status,
        );

        // The key left the storefront immediately, not at delivery time.
        $this->assertSame(2, $product->fresh()->available_keys_count);
    }

    public function test_several_buyers_each_get_their_own_key(): void
    {
        $product = $this->productWithKeys(3);

        foreach (range(1, 3) as $i) {
            $this->postJson('/api/orders', ['sku' => $product->sku])->assertCreated();
        }

        $this->assertSame(0, $product->fresh()->available_keys_count);
        $this->assertSame(3, Reservation::whereNull('cancelled_at')->count());

        // Three distinct keys, not the same one three times.
        $this->assertSame(3, Reservation::whereNull('cancelled_at')->distinct()->count('product_key_id'));
    }

    public function test_expired_hold_returns_the_key_to_the_storefront(): void
    {
        $product = $this->productWithKeys(1);

        $orderId = $this->postJson('/api/orders', ['sku' => $product->sku])->json('data.id');
        $this->assertSame(0, $product->fresh()->available_keys_count);

        Reservation::where('order_id', $orderId)->update([
            'expires_at' => now()->subMinute(),
        ]);

        $released = app(ReservationService::class)->cancelExpiredReservations();

        $this->assertSame(1, $released);
        $this->assertSame(1, $product->fresh()->available_keys_count, 'Key must go back on sale.');
        $this->assertNotNull(Reservation::where('order_id', $orderId)->first()->cancelled_at);

        $key = ProductKey::where('product_id', $product->id)->firstOrFail();
        $this->assertSame(ProductKeyStatus::Available, $key->status);
        $this->assertNull($key->order_id);
    }

    public function test_released_key_can_be_bought_by_someone_else(): void
    {
        $product = $this->productWithKeys(1);

        $firstOrder = $this->postJson('/api/orders', ['sku' => $product->sku])->json('data.id');

        Reservation::where('order_id', $firstOrder)->update(['expires_at' => now()->subMinute()]);
        app(ReservationService::class)->cancelExpiredReservations();

        // The whole point of releasing: the next buyer can actually get it.
        $this->postJson('/api/orders', ['sku' => $product->sku])->assertCreated();
    }

    public function test_remaining_time_counts_down_rather_than_reporting_zero(): void
    {
        $product = $this->productWithKeys(1);
        $orderId = $this->postJson('/api/orders', ['sku' => $product->sku])->json('data.id');

        $remaining = $this->getJson("/api/orders/{$orderId}")->json('data.reservation.remaining_seconds');

        // Carbon 3 returns a signed difference; the wrong argument order silently
        // pinned this to 0 and the countdown never rendered.
        $this->assertGreaterThan(0, $remaining);
        $this->assertLessThanOrEqual(ReservationService::RESERVATION_TTL_MINUTES * 60, $remaining);
    }

    public function test_status_broadcast_carries_the_issued_key(): void
    {
        $product = $this->productWithKeys(1);
        $orderId = $this->postJson('/api/orders', ['sku' => $product->sku])->json('data.id');

        $this->postJson('/api/webhooks/payment', [
            'event_id' => 'evt_broadcast_key',
            'order_id' => $orderId,
            'status' => 'paid',
            'amount' => $product->price,
            'currency' => $product->currency,
            'created_at' => now()->toIso8601String(),
        ])->assertOk();

        $order = Order::with('delivery')->findOrFail($orderId);
        $payload = (new OrderStatusChanged($order, 'delivering', 'delivered'))->broadcastWith();

        // Without this the order page flipped to "delivered" and kept showing the
        // waiting spinner until the shopper reloaded.
        $this->assertNotNull($payload['delivery'], 'The key must travel with the status.');
        $this->assertSame($order->delivery->code, $payload['delivery']['code']);
        $this->assertSame('completed', $payload['delivery']['status']);
    }

    public function test_delivery_uses_the_key_the_reservation_held(): void
    {
        $product = $this->productWithKeys(2);
        $orderId = $this->postJson('/api/orders', ['sku' => $product->sku])->json('data.id');

        $heldKeyId = Reservation::where('order_id', $orderId)->firstOrFail()->product_key_id;

        $this->postJson('/api/webhooks/payment', [
            'event_id' => 'evt_reserved_delivery',
            'order_id' => $orderId,
            'status' => 'paid',
            'amount' => $product->price,
            'currency' => $product->currency,
            'created_at' => now()->toIso8601String(),
        ])->assertOk();

        $order = Order::findOrFail($orderId);
        $this->assertSame(OrderStatus::Delivered, $order->fresh()->status);

        // The held key is the one issued — a second key was not burned.
        $this->assertSame($heldKeyId, ProductKey::where('order_id', $orderId)->firstOrFail()->id);
        $this->assertSame(1, $product->fresh()->available_keys_count);
    }
}
