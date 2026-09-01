<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\ProductKeyStatus;
use App\Models\Delivery;
use App\Models\Order;
use App\Models\PaymentEvent;
use App\Models\Product;
use App\Models\ProductKey;
use App\Models\SupplierRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DatabaseConstraintsTest extends TestCase
{
    use RefreshDatabase;

    public function test_rejects_duplicate_event_id(): void
    {
        $product = Product::factory()->create(['sku' => 'CONST_TEST']);
        $order = $product->orders()->create([
            'sku' => 'CONST_TEST',
            'price' => 1000.00,
            'currency' => 'RUB',
            'status' => OrderStatus::Created,
        ]);

        PaymentEvent::create([
            'event_id' => 'evt_const_001',
            'order_id' => $order->id,
            'status' => 'paid',
            'amount' => 1000.00,
            'currency' => 'RUB',
            'created_at' => now(),
        ]);

        try {
            PaymentEvent::create([
                'event_id' => 'evt_const_001',
                'order_id' => $order->id,
                'status' => 'paid',
                'amount' => 1000.00,
                'currency' => 'RUB',
                'created_at' => now(),
            ]);
            $this->fail('Expected unique constraint violation');
        } catch (\Exception $e) {
            $this->assertStringContainsString('UNIQUE', $e->getMessage());
        }
    }

    public function test_rejects_duplicate_delivery_per_order(): void
    {
        $product = Product::factory()->create(['sku' => 'DEL_CONST_TEST']);
        $order = $product->orders()->create([
            'sku' => 'DEL_CONST_TEST',
            'price' => 1000.00,
            'currency' => 'RUB',
            'status' => OrderStatus::Paid,
        ]);

        Delivery::create([
            'order_id' => $order->id,
            'request_id' => 'req_del_001',
            'supplier' => 'supplier_a',
            'status' => 'completed',
            'code' => 'KEY_001',
        ]);

        try {
            Delivery::create([
                'order_id' => $order->id,
                'request_id' => 'req_del_002',
                'supplier' => 'supplier_b',
                'status' => 'completed',
                'code' => 'KEY_002',
            ]);
            $this->fail('Expected unique constraint violation');
        } catch (\Exception $e) {
            $this->assertStringContainsString('UNIQUE', $e->getMessage());
        }
    }

    public function test_rejects_duplicate_product_key_code(): void
    {
        $product = Product::factory()->create(['sku' => 'KEY_CONST_TEST']);

        ProductKey::create([
            'product_id' => $product->id,
            'code' => 'UNIQUE_CODE_001',
            'status' => ProductKeyStatus::Available,
        ]);

        try {
            ProductKey::create([
                'product_id' => $product->id,
                'code' => 'UNIQUE_CODE_001',
                'status' => ProductKeyStatus::Available,
            ]);
            $this->fail('Expected unique constraint violation');
        } catch (\Exception $e) {
            $this->assertStringContainsString('UNIQUE', $e->getMessage());
        }
    }

    public function test_rejects_duplicate_supplier_request_id(): void
    {
        SupplierRequest::create([
            'request_id' => 'req_sr_001',
            'supplier' => 'supplier_a',
            'sku' => 'TEST_SKU',
            'status' => 'success',
            'code' => 'KEY_001',
        ]);

        try {
            SupplierRequest::create([
                'request_id' => 'req_sr_001',
                'supplier' => 'supplier_a',
                'sku' => 'TEST_SKU',
                'status' => 'success',
                'code' => 'KEY_002',
            ]);
            $this->fail('Expected unique constraint violation');
        } catch (\Exception $e) {
            $this->assertStringContainsString('UNIQUE', $e->getMessage());
        }
    }
}
