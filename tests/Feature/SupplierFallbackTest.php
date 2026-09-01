<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\DTO\IssueRequest;
use App\DTO\IssueResult;
use App\Enums\OrderStatus;
use App\Models\Delivery;
use App\Models\Order;
use App\Models\Product;
use App\Services\DeliveryService;
use App\Suppliers\Contracts\SupplierInterface;
use App\Suppliers\SupplierManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class SupplierFallbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_falls_back_to_supplier_b_when_a_fails(): void
    {
        Config::set('suppliers.max_retries', 1);
        Config::set('suppliers.retry_backoff_ms', [0]);

        $product = Product::factory()->create(['sku' => 'FALLBACK_TEST']);
        $order = $product->orders()->create([
            'sku' => 'FALLBACK_TEST',
            'price' => 1000.00,
            'currency' => 'RUB',
            'status' => OrderStatus::Paid,
        ]);

        $mockA = $this->mock(SupplierInterface::class, function ($mock) {
            $mock->shouldReceive('name')->andReturn('supplier_a');
            $mock->shouldReceive('issue')->andReturn(IssueResult::failure('Supplier A down'));
        });

        $mockB = $this->mock(SupplierInterface::class, function ($mock) {
            $mock->shouldReceive('name')->andReturn('supplier_b');
            $mock->shouldReceive('issue')->andReturn(IssueResult::success('SB_FALLBACK_KEY'));
        });

        $mockManager = $this->mock(SupplierManager::class);
        $mockManager->shouldReceive('driver')->with('a')->andReturn($mockA);
        $mockManager->shouldReceive('driver')->with('b')->andReturn($mockB);

        $deliveryService = new DeliveryService($mockManager, app(\App\Services\LedgerService::class), app(\App\Services\CatalogService::class));
        $deliveryService->deliver($order);

        $order->refresh();

        $this->assertEquals(OrderStatus::Delivered->value, $order->status->value);

        $delivery = Delivery::where('order_id', $order->id)->first();
        $this->assertNotNull($delivery);
        $this->assertEquals('supplier_b', $delivery->supplier);
        $this->assertEquals('SB_FALLBACK_KEY', $delivery->code);
    }
}
