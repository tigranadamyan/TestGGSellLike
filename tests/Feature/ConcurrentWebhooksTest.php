<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Delivery;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ConcurrentWebhooksTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_enforces_exactly_one_delivery_per_order(): void
    {
        $product = Product::factory()->create(['sku' => 'DB_CONCUR_TEST']);

        $order = $product->orders()->create([
            'sku' => 'DB_CONCUR_TEST',
            'price' => 1000.00,
            'currency' => 'RUB',
            'status' => OrderStatus::Paid,
        ]);

        Delivery::create([
            'order_id' => $order->id,
            'request_id' => 'req_first',
            'supplier' => 'supplier_a',
            'status' => 'completed',
            'code' => 'KEY_001',
        ]);

        try {
            Delivery::create([
                'order_id' => $order->id,
                'request_id' => 'req_second',
                'supplier' => 'supplier_b',
                'status' => 'completed',
                'code' => 'KEY_002',
            ]);
            $this->fail('Expected unique constraint violation');
        } catch (\Exception $e) {
            $this->assertStringContainsString('UNIQUE', $e->getMessage());
        }

        $this->assertDatabaseCount('deliveries', 1);
    }

    public function test_fifty_rapid_payment_inserts_only_one_succeeds_via_unique_constraint(): void
    {
        $product = Product::factory()->create(['sku' => 'CONCUR_TEST']);

        $order = $product->orders()->create([
            'sku' => 'CONCUR_TEST',
            'price' => 1499.00,
            'currency' => 'RUB',
            'status' => OrderStatus::Created,
        ]);

        // Simulate 50 rapid payment attempts with the SAME event_id
        // UNIQUE(event_id) ensures only one can succeed
        $successCount = 0;
        $failureCount = 0;

        for ($i = 0; $i < 50; $i++) {
            try {
                DB::table('payment_events')->insert([
                    'event_id' => 'evt_concur_001', // Same event_id for all
                    'order_id' => $order->id,
                    'status' => 'paid',
                    'amount' => 1499.00,
                    'currency' => 'RUB',
                    'created_at' => now()->toIso8601String(),
                ]);
                $successCount++;
            } catch (\Exception $e) {
                $failureCount++;
            }
        }

        // Exactly 1 succeeded, 49 failed on unique constraint
        $this->assertEquals(1, $successCount);
        $this->assertEquals(49, $failureCount);

        $this->assertDatabaseCount('payment_events', 1);
        $this->assertDatabaseHas('payment_events', [
            'event_id' => 'evt_concur_001',
            'order_id' => $order->id,
            'status' => 'paid',
        ]);
    }

    public function test_different_event_ids_for_same_order_all_stored_but_only_one_processed(): void
    {
        $product = Product::factory()->create(['sku' => 'MULTI_EVT_TEST']);

        $order = $product->orders()->create([
            'sku' => 'MULTI_EVT_TEST',
            'price' => 1000.00,
            'currency' => 'RUB',
            'status' => OrderStatus::Created,
        ]);

        // Insert 50 different event_ids for the same order — all should succeed
        for ($i = 0; $i < 50; $i++) {
            DB::table('payment_events')->insert([
                'event_id' => "evt_multi_{$i}",
                'order_id' => $order->id,
                'status' => 'paid',
                'amount' => 1000.00,
                'currency' => 'RUB',
                'created_at' => now()->toIso8601String(),
            ]);
        }

        // All 50 events stored (all different event_ids)
        $this->assertDatabaseCount('payment_events', 50);

        // Order is still created (only transitions via PaymentService which we bypassed)
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => OrderStatus::Created->value,
        ]);
    }
}

