<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\DTO\IssueRequest;
use App\DTO\IssueResult;
use App\Enums\OrderStatus;
use App\Models\SupplierRequest;
use App\Services\DeliveryService;
use App\Suppliers\SupplierA;
use App\Suppliers\Contracts\SupplierInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class SupplierTimeoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_supplier_returns_stored_result_on_retry_after_timeout(): void
    {
        Config::set('suppliers.a.failure_rate', 0);
        Config::set('suppliers.a.timeout_rate', 0);
        Config::set('suppliers.a.delay_ms', 0);

        $supplier = new SupplierA;

        // Simulate: supplier already processed this request_id
        SupplierRequest::create([
            'request_id' => 'req_timeout_test_001',
            'supplier' => 'supplier_a',
            'sku' => 'TIMEOUT_SKU',
            'status' => 'success',
            'code' => 'SA_TIMEOUT_KEY',
        ]);

        // Issue with same request_id — should return stored result
        $request = new IssueRequest(
            requestId: 'req_timeout_test_001',
            sku: 'TIMEOUT_SKU',
            orderId: '1',
        );

        $result = $supplier->issue($request);

        $this->assertTrue($result->success);
        $this->assertEquals('SA_TIMEOUT_KEY', $result->code);

        $this->assertDatabaseCount('supplier_requests', 1);
    }

    public function test_timeout_that_actually_issued_a_code_does_not_double_issue(): void
    {
        // Supplier A always "hangs": it issues the code, persists it, then the
        // response is lost. This is the trap — a timeout is not a failure.
        Config::set('suppliers.a.timeout_rate', 1.0);
        Config::set('suppliers.a.failure_rate', 0);
        Config::set('suppliers.a.out_of_stock_rate', 0);
        Config::set('suppliers.max_retries', 3);
        Config::set('suppliers.retry_backoff_ms', [0, 0, 0]);

        // No local keys, so the supplier path is exercised.
        $product = \App\Models\Product::factory()->create(['sku' => 'TIMEOUT_TRAP']);
        $order = $product->orders()->create([
            'sku' => 'TIMEOUT_TRAP',
            'price' => 1000.00,
            'currency' => 'RUB',
            'status' => OrderStatus::Paid,
        ]);

        app(DeliveryService::class)->deliver($order);

        $order->refresh();
        $this->assertEquals(OrderStatus::Delivered, $order->status);

        // The retry reused the same request_id, so the supplier replayed the code
        // it had already issued instead of minting a second one.
        $requests = SupplierRequest::where('supplier', 'supplier_a')->get();
        $this->assertCount(1, $requests, 'A retry after timeout must not create a second supplier request');
        $this->assertEquals("req_{$order->id}_a", $requests->first()->request_id);

        $delivery = \App\Models\Delivery::where('order_id', $order->id)->first();
        $this->assertEquals($requests->first()->code, $delivery->code);
        $this->assertEquals(1, \App\Models\Delivery::where('order_id', $order->id)->count());
    }

    public function test_delivery_service_uses_a_stable_request_id_per_order_and_supplier(): void
    {
        Config::set('suppliers.max_retries', 2);
        Config::set('suppliers.retry_backoff_ms', [0, 0]);

        $product = \App\Models\Product::factory()->create(['sku' => 'REQID_TEST']);
        $order = $product->orders()->create([
            'sku' => 'REQID_TEST',
            'price' => 1000.00,
            'currency' => 'RUB',
            'status' => OrderStatus::Paid,
        ]);

        $seen = [];
        $mockSupplier = $this->mock(SupplierInterface::class);
        $mockSupplier->shouldReceive('name')->andReturn('supplier_a');
        $mockSupplier->shouldReceive('issue')->andReturnUsing(function (IssueRequest $r) use (&$seen) {
            $seen[] = $r->requestId;

            return IssueResult::timeout();
        });

        $mockManager = $this->mock(\App\Suppliers\SupplierManager::class);
        $mockManager->shouldReceive('driver')->with('a')->andReturn($mockSupplier);
        $mockManager->shouldReceive('driver')->with('b')->andReturn($mockSupplier);

        (new DeliveryService($mockManager, app(\App\Services\LedgerService::class), app(\App\Services\CatalogService::class)))->deliver($order);

        // Every retry against one supplier must reuse the same request_id, which is
        // what lets the supplier deduplicate.
        $this->assertNotEmpty($seen);
        $this->assertEquals(["req_{$order->id}_a", "req_{$order->id}_a"], array_slice($seen, 0, 2));
    }
}
