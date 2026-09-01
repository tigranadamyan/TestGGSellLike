<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\ProductKeyStatus;
use App\Models\Delivery;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductKey;
use App\Services\ReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_recovers_paid_order_without_delivery(): void
    {
        $product = Product::factory()->create(['sku' => 'RECOV_TEST']);

        ProductKey::create([
            'product_id' => $product->id,
            'code' => 'RECOV_KEY_001',
            'status' => ProductKeyStatus::Available,
        ]);

        $order = $product->orders()->create([
            'sku' => 'RECOV_TEST',
            'price' => 1000.00,
            'currency' => 'RUB',
            'status' => OrderStatus::Paid,
        ]);

        $reconciliationService = app(ReconciliationService::class);
        $results = $reconciliationService->reconcile();

        $this->assertContains($order->id, $results['paid_not_delivered']);

        $order->refresh();

        // A local key was available, so recovery must complete the delivery.
        $this->assertEquals(OrderStatus::Delivered, $order->status);
        $this->assertDatabaseHas('product_keys', [
            'code' => 'RECOV_KEY_001',
            'status' => \App\Enums\ProductKeyStatus::Issued->value,
            'order_id' => $order->id,
        ]);
    }

    public function test_does_not_create_duplicate_delivery_during_recovery(): void
    {
        $product = Product::factory()->create(['sku' => 'NO_DUP_TEST']);

        ProductKey::create([
            'product_id' => $product->id,
            'code' => 'NO_DUP_KEY_001',
            'status' => ProductKeyStatus::Available,
        ]);

        $order = $product->orders()->create([
            'sku' => 'NO_DUP_TEST',
            'price' => 1000.00,
            'currency' => 'RUB',
            'status' => OrderStatus::Paid,
        ]);

        $reconciliationService = app(ReconciliationService::class);

        $reconciliationService->reconcile();
        $reconciliationService->reconcile();
        $reconciliationService->reconcile();

        // Three reconciliation passes must produce exactly one delivery and
        // consume exactly one key — "at most 1" would also pass on zero.
        $this->assertEquals(1, Delivery::where('order_id', $order->id)->count());
        $this->assertEquals(1, ProductKey::where('order_id', $order->id)->count());
        $this->assertEquals(OrderStatus::Delivered, $order->fresh()->status);
        // The order was seeded as `paid` without a webhook, so no payment was ever
        // posted — reconciliation surfaces that as a "delivered but not paid" anomaly.
        $this->assertEquals(0, \App\Models\MoneyMovement::where('type', 'payment_received')->count());
        $this->assertEquals(0.0, round((float) \App\Models\MoneyMovement::sum('amount'), 2));
    }
}
