<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\LedgerAccount;
use App\Enums\OrderStatus;
use App\Enums\ProductKeyStatus;
use App\Models\MoneyMovement;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductKey;
use App\Services\LedgerService;
use App\Services\ReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Stage 4: the money journal must always reconcile.
 */
class MoneyLedgerTest extends TestCase
{
    use RefreshDatabase;

    private function orderFor(string $sku, float $price, bool $withKey = true): Order
    {
        $product = Product::factory()->create(['sku' => $sku, 'price' => $price]);

        if ($withKey) {
            ProductKey::create([
                'product_id' => $product->id,
                'code' => $sku.'_KEY',
                'status' => ProductKeyStatus::Available,
            ]);
        }

        return $product->orders()->create([
            'sku' => $sku,
            'price' => $price,
            'currency' => 'RUB',
            'status' => OrderStatus::Created,
        ]);
    }

    private function pay(Order $order, string $eventId): void
    {
        $this->postJson('/api/webhooks/payment', [
            'event_id' => $eventId,
            'order_id' => $order->id,
            'status' => 'paid',
            'amount' => (float) $order->price,
            'currency' => 'RUB',
            'created_at' => '2025-01-01T12:00:00Z',
        ])->assertOk();
    }

    public function test_every_posting_is_balanced_and_the_journal_sums_to_zero(): void
    {
        $a = $this->orderFor('LEDGER_A', 1000.00);
        $b = $this->orderFor('LEDGER_B', 2500.00);

        $this->pay($a, 'evt_ledger_a');
        $this->pay($b, 'evt_ledger_b');

        // Every entry_group individually nets to zero.
        $groups = MoneyMovement::selectRaw('entry_group, ROUND(SUM(amount), 2) as net')
            ->groupBy('entry_group')
            ->pluck('net', 'entry_group');

        $this->assertGreaterThan(0, $groups->count());
        foreach ($groups as $group => $net) {
            $this->assertEquals(0.0, (float) $net, "entry group {$group} must net to zero");
        }

        $this->assertEquals(0.0, round((float) MoneyMovement::sum('amount'), 2));
    }

    public function test_payment_creates_an_obligation_that_delivery_discharges(): void
    {
        $order = $this->orderFor('LEDGER_FLOW', 1290.00);
        $this->pay($order, 'evt_ledger_flow');

        $ledger = app(LedgerService::class);
        $report = $ledger->report();

        // Delivery runs inline on the sync queue, so by now the full cycle is done:
        // cash in, obligation raised by the payment and then discharged by the
        // delivery, revenue recognised.
        $this->assertEquals(OrderStatus::Delivered, $order->fresh()->status);

        $this->assertEquals(1290.00, $report['balances'][LedgerAccount::Cash->value]);
        $this->assertEquals(0.0, $report['balances'][LedgerAccount::DeferredRevenue->value]);
        $this->assertEquals(-1290.00, $report['balances'][LedgerAccount::Revenue->value]);

        $this->assertEquals(0.0, $report['owed_to_customers'], 'nothing owed once delivered');
        $this->assertTrue($report['balanced']);
        $this->assertTrue($report['obligations_match']);

        // Both halves of the cycle are on the books, each as one balanced posting.
        $this->assertEquals(1, MoneyMovement::where('type', 'payment_received')->distinct()->count('entry_group'));
        $this->assertEquals(1, MoneyMovement::where('type', 'revenue_recognised')->distinct()->count('entry_group'));
    }

    public function test_undelivered_order_keeps_the_obligation_on_the_books(): void
    {
        // No local key and no supplier stock: paid, but nothing handed over.
        config(['suppliers.a.out_of_stock_rate' => 1.0, 'suppliers.b.out_of_stock_rate' => 1.0]);
        config(['suppliers.max_retries' => 1, 'suppliers.retry_backoff_ms' => [0]]);

        $order = $this->orderFor('LEDGER_OWED', 3490.00, withKey: false);
        $this->pay($order, 'evt_ledger_owed');

        $this->assertEquals(OrderStatus::OutOfStock, $order->fresh()->status);

        $report = app(LedgerService::class)->report();

        $this->assertTrue($report['balanced'], 'journal still sums to zero');
        $this->assertEquals(3490.00, $report['owed_to_customers']);
        $this->assertEquals(3490.00, $report['undelivered_order_value']);
        $this->assertTrue($report['obligations_match'], 'what we owe must equal undelivered order value');
    }

    public function test_revenue_is_recognised_only_once_across_repeated_deliveries(): void
    {
        $order = $this->orderFor('LEDGER_ONCE', 890.00);
        $this->pay($order, 'evt_ledger_once');

        $service = app(\App\Services\DeliveryService::class);
        for ($i = 0; $i < 5; $i++) {
            $service->deliver($order->fresh());
        }

        $this->assertEquals(1, MoneyMovement::where('type', 'revenue_recognised')
            ->distinct()->count('entry_group'));

        $report = app(LedgerService::class)->report();
        $this->assertTrue($report['balanced']);
        $this->assertEquals(890.00, $report['balances'][LedgerAccount::Revenue->value] * -1);
    }

    public function test_an_unbalanced_posting_is_refused(): void
    {
        $order = $this->orderFor('LEDGER_BAD', 100.00, withKey: false);

        $ledger = new class extends LedgerService
        {
            public function postBroken(Order $order): void
            {
                // Deliberately lopsided: 100 in, 90 out.
                $method = new \ReflectionMethod(LedgerService::class, 'post');
                $method->invoke($this, $order, \App\Enums\LedgerEntryType::PaymentReceived, 'RUB', [
                    [LedgerAccount::Cash, 100.00],
                    [LedgerAccount::DeferredRevenue, -90.00],
                ]);
            }
        };

        $this->expectException(\LogicException::class);
        $ledger->postBroken($order);
    }

    public function test_reconciliation_reports_a_settled_amount_that_does_not_match_the_order(): void
    {
        $order = $this->orderFor('LEDGER_MISMATCH', 1000.00);

        // The payment system settles the wrong amount.
        $this->postJson('/api/webhooks/payment', [
            'event_id' => 'evt_mismatch',
            'order_id' => $order->id,
            'status' => 'paid',
            'amount' => 999.00,
            'currency' => 'RUB',
            'created_at' => '2025-01-01T12:00:00Z',
        ])->assertOk();

        $results = app(ReconciliationService::class)->reconcile();

        $this->assertContains($order->id, $results['amount_mismatch']);

        // The journal itself still balances — the mismatch is an anomaly to
        // investigate, not a corruption of the books.
        $this->assertTrue($results['ledger']['balanced']);
        $this->assertSame([], $results['ledger']['unbalanced_groups']);
    }
}
