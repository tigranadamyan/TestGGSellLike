<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\ProductKeyStatus;
use App\Models\Order;
use App\Models\PaymentEvent;
use App\Models\Product;
use App\Models\ProductKey;
use App\Models\Reservation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Double clicks, reloads, back buttons and dropped connections must not create a
 * second order or a second charge.
 */
class PurchaseResilienceTest extends TestCase
{
    use RefreshDatabase;

    private function product(int $keys = 3, string $sku = 'RESILIENT'): Product
    {
        $product = Product::factory()->create(['sku' => $sku, 'available_keys_count' => $keys]);

        for ($i = 0; $i < $keys; $i++) {
            ProductKey::create([
                'product_id' => $product->id,
                'code' => $sku.'-K'.$i,
                'status' => ProductKeyStatus::Available,
            ]);
        }

        return $product;
    }

    public function test_repeated_submit_with_one_key_creates_one_order(): void
    {
        $product = $this->product();
        $headers = ['X-Idempotency-Key' => 'double-click-1'];

        $first = $this->postJson('/api/orders', ['sku' => $product->sku], $headers);
        $second = $this->postJson('/api/orders', ['sku' => $product->sku], $headers);

        $first->assertCreated();
        $second->assertOk();

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, Order::count());

        // Only one key left the shelf.
        $this->assertSame(2, $product->fresh()->available_keys_count);
    }

    public function test_a_finished_purchase_does_not_block_buying_the_same_item_again(): void
    {
        $product = $this->product();

        $one = $this->postJson('/api/orders', ['sku' => $product->sku], ['X-Idempotency-Key' => 'buy-1']);
        $two = $this->postJson('/api/orders', ['sku' => $product->sku], ['X-Idempotency-Key' => 'buy-2']);

        $one->assertCreated();
        $two->assertCreated();

        $this->assertNotSame($one->json('data.id'), $two->json('data.id'));
        $this->assertSame(2, Order::count());
    }

    public function test_paying_twice_changes_nothing(): void
    {
        $product = $this->product();
        $orderId = $this->postJson('/api/orders', ['sku' => $product->sku])->json('data.id');

        $pay = fn (string $eventId) => $this->postJson('/api/webhooks/payment', [
            'event_id' => $eventId,
            'order_id' => $orderId,
            'status' => 'paid',
            'amount' => $product->price,
            'currency' => $product->currency,
            'created_at' => now()->toIso8601String(),
        ]);

        $pay('evt_first')->assertOk();
        $issuedCode = Order::with('delivery')->findOrFail($orderId)->delivery->code;

        // A different event id, i.e. a genuine second charge attempt.
        $pay('evt_second')->assertOk()->assertJson(['outcome' => 'ignored']);

        $order = Order::with('delivery')->findOrFail($orderId);
        $this->assertSame(OrderStatus::Delivered, $order->status);
        $this->assertSame($issuedCode, $order->delivery->code);
        $this->assertSame(2, $product->fresh()->available_keys_count, 'A second key must not be burned.');
    }

    public function test_payment_is_never_dropped_when_the_hold_lapsed(): void
    {
        $product = $this->product(1);
        $orderId = $this->postJson('/api/orders', ['sku' => $product->sku])->json('data.id');

        // The shopper paid a moment after the timer ran out.
        Reservation::where('order_id', $orderId)->update(['expires_at' => now()->subMinute()]);

        $this->postJson('/api/webhooks/payment', [
            'event_id' => 'evt_late',
            'order_id' => $orderId,
            'status' => 'paid',
            'amount' => $product->price,
            'currency' => $product->currency,
            'created_at' => now()->toIso8601String(),
        ])->assertOk();

        // The money moved, so the event must be recorded and the order must move
        // on. Rejecting it left the order in `created` with the payment invisible
        // to every recovery path.
        $event = PaymentEvent::where('event_id', 'evt_late')->firstOrFail();
        $this->assertNotNull($event->processed_at, 'A received payment must be applied.');

        $this->assertNotSame(OrderStatus::Created, Order::findOrFail($orderId)->status);
    }
}
