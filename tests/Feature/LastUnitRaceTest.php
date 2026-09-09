<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\ProductKeyStatus;
use App\Exceptions\OutOfStockException;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductKey;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Two buyers, one key left. Exactly one wins; the other is told plainly, and
 * nobody ends up paying for something that is not there.
 */
class LastUnitRaceTest extends TestCase
{
    use RefreshDatabase;

    private function productWithOneKey(): Product
    {
        $product = Product::factory()->create([
            'sku' => 'LAST_ONE',
            'available_keys_count' => 1,
        ]);

        ProductKey::create([
            'product_id' => $product->id,
            'code' => 'LAST-ONE-KEY',
            'status' => ProductKeyStatus::Available,
        ]);

        return $product;
    }

    public function test_only_one_buyer_gets_the_last_unit(): void
    {
        $product = $this->productWithOneKey();

        $first = $this->postJson('/api/orders', ['sku' => $product->sku]);
        $second = $this->postJson('/api/orders', ['sku' => $product->sku]);

        $first->assertCreated();
        $second->assertStatus(409);

        $this->assertSame(0, $product->fresh()->available_keys_count);
        $this->assertSame(1, Order::count(), 'The loser must not leave an order behind.');
    }

    public function test_the_loser_is_told_what_happened_not_shown_an_error(): void
    {
        $product = $this->productWithOneKey();

        $this->postJson('/api/orders', ['sku' => $product->sku])->assertCreated();

        $this->postJson('/api/orders', ['sku' => $product->sku])
            ->assertStatus(409)
            ->assertJson([
                'error' => 'sold_out',
                'sku' => $product->sku,
            ])
            ->assertJsonStructure(['error', 'message', 'sku']);
    }

    public function test_losing_the_race_leaves_nothing_payable(): void
    {
        $product = $this->productWithOneKey();
        $this->postJson('/api/orders', ['sku' => $product->sku])->assertCreated();

        $before = Order::count();
        $this->postJson('/api/orders', ['sku' => $product->sku])->assertStatus(409);

        // No order, so there is nothing a payment could ever attach to.
        $this->assertSame($before, Order::count());
        $this->assertDatabaseCount('reservations', 1);
    }

    public function test_service_signals_sold_out_rather_than_a_database_error(): void
    {
        $product = $this->productWithOneKey();
        $service = app(OrderService::class);

        $service->createOrder($product);

        // The old design raised a unique-constraint violation here, which surfaced
        // to the shopper as a 500 with a Postgres error dump.
        $this->expectException(OutOfStockException::class);
        $service->createOrder($product);
    }

    public function test_winner_still_completes_the_purchase(): void
    {
        $product = $this->productWithOneKey();
        $orderId = $this->postJson('/api/orders', ['sku' => $product->sku])->json('data.id');

        $this->postJson('/api/webhooks/payment', [
            'event_id' => 'evt_last_unit',
            'order_id' => $orderId,
            'status' => 'paid',
            'amount' => $product->price,
            'currency' => $product->currency,
            'created_at' => now()->toIso8601String(),
        ])->assertOk();

        $this->assertSame(OrderStatus::Delivered, Order::findOrFail($orderId)->status);
    }
}
