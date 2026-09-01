<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\DTO\IssueRequest;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\PaymentEvent;
use App\Models\Product;
use App\Models\SupplierRequest;
use App\Suppliers\SupplierA;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IdempotencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_same_event_id_does_not_create_duplicate_payment(): void
    {
        $product = Product::factory()->create(['sku' => 'IDEM_TEST']);
        $order = $product->orders()->create([
            'sku' => 'IDEM_TEST',
            'price' => 1000.00,
            'currency' => 'RUB',
            'status' => OrderStatus::Created,
        ]);

        $payload = [
            'event_id' => 'evt_idem_001',
            'order_id' => $order->id,
            'status' => 'paid',
            'amount' => 1000.00,
            'currency' => 'RUB',
            'created_at' => now()->toIso8601String(),
        ];

        $this->postJson('/api/webhooks/payment', $payload);
        $this->postJson('/api/webhooks/payment', $payload);

        $this->assertDatabaseCount('payment_events', 1);

        // One balanced posting (two rows), not one row.
        $this->assertEquals(1, \App\Models\MoneyMovement::where('type', 'payment_received')
            ->distinct()->count('entry_group'));
        $this->assertEquals(0.0, round((float) \App\Models\MoneyMovement::sum('amount'), 2));
    }

    public function test_same_request_id_returns_same_result_from_supplier(): void
    {
        $supplier = new SupplierA;
        $request = new IssueRequest(
            requestId: 'req_idem_test_001',
            sku: 'TEST_SKU',
            orderId: '1',
        );

        $result1 = $supplier->issue($request);
        $result2 = $supplier->issue($request);

        $this->assertEquals($result1->code, $result2->code);
        $this->assertEquals($result1->success, $result2->success);

        $this->assertDatabaseCount('supplier_requests', 1);
    }
}
