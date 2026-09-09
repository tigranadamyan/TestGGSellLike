<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\ProductKeyStatus;
use App\Models\Delivery;
use App\Models\MoneyMovement;
use App\Models\PaymentEvent;
use App\Models\Product;
use App\Models\ProductKey;
use App\Models\SupplierRequest;
use App\Services\DeliveryService;
use App\Services\ReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * One test per acceptance criterion from the assignment. These drive the real
 * controller/service stack (queue is `sync`, so delivery runs inline).
 *
 * True OS-level parallelism cannot be reproduced against sqlite :memory:; the
 * wall-clock race is covered by scripts/race-test.php against Postgres.
 */
class AcceptanceCriteriaTest extends TestCase
{
    use RefreshDatabase;

    private function productWithKeys(string $sku, int $keys = 5): Product
    {
        $product = Product::factory()->create(['sku' => $sku]);

        for ($i = 1; $i <= $keys; $i++) {
            ProductKey::create([
                'product_id' => $product->id,
                'code' => "{$sku}_KEY_{$i}",
                'status' => ProductKeyStatus::Available,
            ]);
        }

        return $product;
    }

    private function createOrder(string $sku): int
    {
        return $this->postJson('/api/orders', ['sku' => $sku])
            ->assertCreated()
            ->json('data.id');
    }

    /**
     * An order placed with no local key on the shelf.
     *
     * The storefront refuses this (a shopper must not pay for something that is
     * gone — see LastUnitRaceTest), but the supplier fallback and restock
     * recovery paths below are precisely about orders in that position, so they
     * are placed explicitly instead of through the public endpoint.
     */
    private function createBackorder(string $sku): int
    {
        $product = Product::where('sku', $sku)->firstOrFail();

        return app(\App\Services\OrderService::class)
            ->createOrder($product, null, requireStock: false)
            ->id;
    }

    /** The payment system always settles the order's actual price. */
    private function webhook(int $orderId, string $eventId, string $status = 'paid'): \Illuminate\Testing\TestResponse
    {
        $amount = (float) \App\Models\Order::find($orderId)->price;

        return $this->postJson('/api/webhooks/payment', [
            'event_id' => $eventId,
            'order_id' => $orderId,
            'status' => $status,
            'amount' => $amount,
            'currency' => 'RUB',
            'created_at' => '2025-01-01T12:00:00Z',
        ]);
    }

    /** Number of balanced ledger postings of a given type for an order. */
    private function ledgerEntries(int $orderId, string $type): int
    {
        return MoneyMovement::where('order_id', $orderId)
            ->where('type', $type)
            ->distinct()
            ->count('entry_group');
    }

    /** Asserts the money/inventory invariants that must hold for a delivered order. */
    private function assertIssuedExactlyOnce(int $orderId): void
    {
        $this->assertEquals(OrderStatus::Delivered, \App\Models\Order::find($orderId)->status);
        $this->assertEquals(1, Delivery::where('order_id', $orderId)->count(), 'exactly one delivery');
        $this->assertEquals(1, ProductKey::where('order_id', $orderId)->count(), 'exactly one key consumed');
        $this->assertNotNull(Delivery::where('order_id', $orderId)->first()->code);

        // The ledger records the payment once and recognises revenue once.
        $this->assertEquals(1, $this->ledgerEntries($orderId, 'payment_received'), 'exactly one payment posting');
        $this->assertEquals(1, $this->ledgerEntries($orderId, 'revenue_recognised'), 'exactly one revenue posting');

        $report = app(\App\Services\LedgerService::class)->report();
        $this->assertTrue($report['balanced'], 'ledger must sum to zero');
        $this->assertSame([], $report['unbalanced_groups']);
        $this->assertTrue($report['obligations_match'], 'deferred revenue must equal undelivered order value');
    }

    // 1) 50 parallel "paid" webhooks for one order — exactly one issuance.
    public function test_criterion_1_fifty_repeated_webhooks_issue_exactly_once(): void
    {
        $this->productWithKeys('C1_SKU');
        $orderId = $this->createOrder('C1_SKU');

        for ($i = 0; $i < 50; $i++) {
            $this->webhook($orderId, 'evt_c1_same')->assertOk();
        }

        $this->assertEquals(1, PaymentEvent::count(), 'redelivery must not duplicate the event');
        $this->assertIssuedExactlyOnce($orderId);
    }

    // 1b) The harder race: 50 DISTINCT events for the same order. All must be
    // recorded (no loss), but only one may move money or issue a key.
    public function test_criterion_1_fifty_distinct_events_still_issue_exactly_once(): void
    {
        $this->productWithKeys('C1B_SKU');
        $orderId = $this->createOrder('C1B_SKU');

        for ($i = 0; $i < 50; $i++) {
            $this->webhook($orderId, "evt_c1b_$i")->assertOk();
        }

        $this->assertEquals(50, PaymentEvent::count(), 'no event may be lost');
        $this->assertIssuedExactlyOnce($orderId);
    }

    // 2) A repeated event_id changes nothing.
    public function test_criterion_2_repeated_event_id_is_a_no_op(): void
    {
        $this->productWithKeys('C2_SKU');
        $orderId = $this->createOrder('C2_SKU');

        $this->webhook($orderId, 'evt_c2')->assertOk()->assertJson(['outcome' => 'applied', 'duplicate' => false]);

        $snapshot = [
            \App\Models\Order::find($orderId)->status->value,
            Delivery::where('order_id', $orderId)->first()->code,
        ];

        $this->webhook($orderId, 'evt_c2')->assertOk()->assertJson(['outcome' => 'duplicate', 'duplicate' => true]);

        $this->assertEquals($snapshot, [
            \App\Models\Order::find($orderId)->status->value,
            Delivery::where('order_id', $orderId)->first()->code,
        ], 'a duplicate webhook must not change the order or the issued code');

        $this->assertEquals(1, PaymentEvent::count());
        $this->assertIssuedExactlyOnce($orderId);
    }

    // 3a) A webhook that arrives before its order must be accepted, not dropped.
    public function test_criterion_3_webhook_before_order_is_accepted_and_applied_later(): void
    {
        $product = $this->productWithKeys('C3_SKU');

        // Event references an order id that does not exist yet.
        $this->postJson('/api/webhooks/payment', [
            'event_id' => 'evt_c3_early',
            'order_id' => 4242,
            'status' => 'paid',
            'amount' => 1499.00, // matches the order inserted below
            'currency' => 'RUB',
            'created_at' => '2025-01-01T12:00:00Z',
        ])->assertOk()->assertJson(['outcome' => 'pending_order']);

        $this->assertDatabaseHas('payment_events', [
            'event_id' => 'evt_c3_early',
            'claimed_order_id' => 4242,
            'processed_at' => null,
        ]);

        // The order shows up later under that id (explicit id => raw insert).
        \Illuminate\Support\Facades\DB::table('orders')->insert([
            'id' => 4242,
            'product_id' => $product->id,
            'sku' => 'C3_SKU',
            'price' => 1499.00,
            'currency' => 'RUB',
            'status' => OrderStatus::Created->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app(ReconciliationService::class)->reconcile();

        $this->assertIssuedExactlyOnce(4242);
    }

    // 3b) Out-of-order delivery: a terminal decision is not overwritten by a
    // late-arriving earlier event.
    public function test_criterion_3_out_of_order_events_do_not_resurrect_a_final_order(): void
    {
        $this->productWithKeys('C3B_SKU');
        $orderId = $this->createOrder('C3B_SKU');

        $this->webhook($orderId, 'evt_c3b_failed', 'failed')->assertOk();
        $this->assertEquals(OrderStatus::PaymentFailed, \App\Models\Order::find($orderId)->status);

        // An older "paid" event arrives afterwards — must be recorded but ignored.
        $this->webhook($orderId, 'evt_c3b_paid', 'paid')->assertOk()->assertJson(['outcome' => 'ignored']);

        $this->assertEquals(OrderStatus::PaymentFailed, \App\Models\Order::find($orderId)->status);
        $this->assertEquals(0, Delivery::where('order_id', $orderId)->count());
        $this->assertEquals(0, ProductKey::where('order_id', $orderId)->count());
        $this->assertEquals(2, PaymentEvent::count(), 'both events are still recorded for audit');
    }

    // 4) Supplier times out but actually issued the code — retry must reuse it.
    public function test_criterion_4_timeout_retry_does_not_double_issue(): void
    {
        Config::set('suppliers.a.timeout_rate', 1.0);
        Config::set('suppliers.a.failure_rate', 0);
        Config::set('suppliers.max_retries', 3);
        Config::set('suppliers.retry_backoff_ms', [0, 0, 0]);

        // No local keys => supplier path.
        Product::factory()->create(['sku' => 'C4_SKU']);
        $orderId = $this->createBackorder('C4_SKU');
        $this->webhook($orderId, 'evt_c4')->assertOk();

        $this->assertEquals(OrderStatus::Delivered, \App\Models\Order::find($orderId)->status);

        $requests = SupplierRequest::where('supplier', 'supplier_a')->get();
        $this->assertCount(1, $requests, 'the retry must reuse the same request_id, not issue a second code');
        $this->assertEquals("req_{$orderId}_a", $requests->first()->request_id);
        $this->assertEquals($requests->first()->code, Delivery::where('order_id', $orderId)->first()->code);
    }

    // 5) Supplier A unavailable, fallback to B, issued exactly once.
    public function test_criterion_5_fallback_to_supplier_b_issues_once(): void
    {
        Config::set('suppliers.a.failure_rate', 1.0);
        Config::set('suppliers.a.timeout_rate', 0);
        Config::set('suppliers.b.failure_rate', 0);
        Config::set('suppliers.b.timeout_rate', 0);
        Config::set('suppliers.max_retries', 2);
        Config::set('suppliers.retry_backoff_ms', [0, 0]);

        Product::factory()->create(['sku' => 'C5_SKU']);
        $orderId = $this->createBackorder('C5_SKU');
        $this->webhook($orderId, 'evt_c5')->assertOk();

        $this->assertEquals(OrderStatus::Delivered, \App\Models\Order::find($orderId)->status);

        $delivery = Delivery::where('order_id', $orderId)->first();
        $this->assertEquals('supplier_b', $delivery->supplier);
        $this->assertStringStartsWith('SB-', $delivery->code);

        $this->assertEquals(1, Delivery::where('order_id', $orderId)->count());
        $this->assertEquals(1, $this->ledgerEntries($orderId, 'payment_received'));
        $this->assertEquals(
            1,
            SupplierRequest::where('supplier', 'supplier_b')->where('status', 'success')->count(),
            'B must issue exactly one code'
        );
    }

    // 6) Empty stock is a recoverable state, not a crash.
    public function test_criterion_6_empty_stock_is_recoverable_without_crashing(): void
    {
        Config::set('suppliers.a.out_of_stock_rate', 1.0);
        Config::set('suppliers.b.out_of_stock_rate', 1.0);
        Config::set('suppliers.a.failure_rate', 0);
        Config::set('suppliers.b.failure_rate', 0);
        Config::set('suppliers.max_retries', 1);
        Config::set('suppliers.retry_backoff_ms', [0]);

        $product = Product::factory()->create(['sku' => 'C6_SKU']);
        $orderId = $this->createBackorder('C6_SKU');

        // No crash, and the payment is still acknowledged.
        $this->webhook($orderId, 'evt_c6')->assertOk();

        $order = \App\Models\Order::find($orderId);
        $this->assertEquals(OrderStatus::OutOfStock, $order->status, 'empty stock is its own recoverable state');
        $this->assertEquals(1, $this->ledgerEntries($orderId, 'payment_received'), 'the payment is still recorded');
        $this->assertEquals(0, $this->ledgerEntries($orderId, 'revenue_recognised'), 'nothing delivered yet, so no revenue');

        // The money still balances while the obligation is outstanding.
        $report = app(\App\Services\LedgerService::class)->report();
        $this->assertTrue($report['balanced']);
        $this->assertEquals(round((float) $order->price, 2), $report['owed_to_customers']);
        $this->assertTrue($report['obligations_match']);

        // Restock, then let the background reconciliation finish the job.
        Config::set('suppliers.a.out_of_stock_rate', 0);
        Config::set('suppliers.b.out_of_stock_rate', 0);
        ProductKey::create([
            'product_id' => $product->id,
            'code' => 'C6_RESTOCK_KEY',
            'status' => ProductKeyStatus::Available,
        ]);

        app(ReconciliationService::class)->reconcile();

        $this->assertIssuedExactlyOnce($orderId);
        $this->assertEquals('C6_RESTOCK_KEY', Delivery::where('order_id', $orderId)->first()->code);
    }

    // Exactly-once also has to survive duplicate delivery workers (job retries,
    // reconciliation racing an in-flight job).
    public function test_repeated_delivery_attempts_consume_only_one_key(): void
    {
        $this->productWithKeys('DUP_SKU', 10);
        $orderId = $this->createOrder('DUP_SKU');
        $this->webhook($orderId, 'evt_dup')->assertOk();

        $order = \App\Models\Order::find($orderId);
        $service = app(DeliveryService::class);

        for ($i = 0; $i < 8; $i++) {
            $service->deliver($order->fresh());
        }

        $this->assertIssuedExactlyOnce($orderId);
        $this->assertEquals(9, ProductKey::where('status', ProductKeyStatus::Available)->count());
    }
}
