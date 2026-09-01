<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\ProductKeyStatus;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductKey;
use App\Services\DeliveryService;
use App\Suppliers\Contracts\SupplierInterface;
use App\Suppliers\SupplierManager;
use App\DTO\IssueResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OutOfStockTest extends TestCase
{
    use RefreshDatabase;

    public function test_transitions_to_delivery_failed_when_no_keys_and_suppliers_fail(): void
    {
        $product = Product::factory()->create(['sku' => 'OOS_TEST']);

        $order = $product->orders()->create([
            'sku' => 'OOS_TEST',
            'price' => 1000.00,
            'currency' => 'RUB',
            'status' => OrderStatus::Paid,
        ]);

        // Mock supplier to fail
        $mockSupplier = $this->mock(SupplierInterface::class);
        $mockSupplier->shouldReceive('name')->andReturn('supplier_a');
        $mockSupplier->shouldReceive('issue')->andReturn(IssueResult::failure('No stock'));

        $mockManager = $this->mock(SupplierManager::class);
        $mockManager->shouldReceive('driver')->with('a')->andReturn($mockSupplier);
        $mockManager->shouldReceive('driver')->with('b')->andReturn($mockSupplier);

        $deliveryService = new DeliveryService($mockManager, app(\App\Services\LedgerService::class), app(\App\Services\CatalogService::class));
        $deliveryService->deliver($order);

        $order->refresh();

        $this->assertEquals(OrderStatus::DeliveryFailed->value, $order->status->value);
    }

    public function test_recovers_from_delivery_failed_when_keys_become_available(): void
    {
        $product = Product::factory()->create(['sku' => 'OOS_REC_TEST']);

        $order = $product->orders()->create([
            'sku' => 'OOS_REC_TEST',
            'price' => 1000.00,
            'currency' => 'RUB',
            'status' => OrderStatus::DeliveryFailed,
        ]);

        ProductKey::create([
            'product_id' => $product->id,
            'code' => 'RECOVERY_KEY_001',
            'status' => ProductKeyStatus::Available,
        ]);

        // DeliveryFailed → Delivering is allowed
        // The delivery service will find the local key and complete
        $deliveryService = app(DeliveryService::class);
        $deliveryService->deliver($order);

        $order->refresh();

        // A restocked key must actually be issued — accepting "delivering" or
        // "delivery_failed" here would let a broken recovery pass.
        $this->assertEquals(OrderStatus::Delivered, $order->status);

        $this->assertDatabaseHas('product_keys', [
            'code' => 'RECOVERY_KEY_001',
            'status' => ProductKeyStatus::Issued->value,
            'order_id' => $order->id,
        ]);

        // Recovery must not consume a second key.
        $this->assertEquals(1, ProductKey::where('order_id', $order->id)->count());
    }
}
