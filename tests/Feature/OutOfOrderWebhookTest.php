<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Jobs\DeliverProductJob;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class OutOfOrderWebhookTest extends TestCase
{
    use RefreshDatabase;

    public function test_webhook_arriving_before_its_order_is_accepted_and_stored(): void
    {
        Queue::fake();

        $response = $this->postJson('/api/webhooks/payment', [
            'event_id' => 'evt_before_order',
            'order_id' => 999999,
            'status' => 'paid',
            'amount' => 1000.00,
            'currency' => 'RUB',
            'created_at' => now()->toIso8601String(),
        ]);

        // The contract reserves 5xx for "retry me" and treats 2xx as accepted.
        // A 4xx here would tell the payment system to stop retrying and the
        // payment would be lost, so the event must be accepted and stored.
        $response->assertOk()->assertJson(['outcome' => 'pending_order']);

        $this->assertDatabaseHas('payment_events', [
            'event_id' => 'evt_before_order',
            'claimed_order_id' => 999999,
            'order_id' => null,
            'processed_at' => null,
        ]);
    }

    public function test_stored_early_webhook_is_applied_once_its_order_exists(): void
    {
        Queue::fake();

        $product = Product::factory()->create(['sku' => 'EARLY_TEST']);
        $order = $product->orders()->create([
            'sku' => 'EARLY_TEST',
            'price' => 1000.00,
            'currency' => 'RUB',
            'status' => OrderStatus::Created,
        ]);

        // Event references the order id, but is delivered before the row exists.
        \App\Models\PaymentEvent::create([
            'event_id' => 'evt_early_apply',
            'order_id' => null,
            'claimed_order_id' => $order->id,
            'status' => 'paid',
            'amount' => 1000.00,
            'currency' => 'RUB',
            'created_at' => now(),
        ]);

        $applied = app(\App\Services\PaymentService::class)->applyOrphanEvents();

        $this->assertSame([$order->id], $applied);
        $this->assertEquals(OrderStatus::Paid, $order->fresh()->status);

        // Exactly one posting — replaying must not double-count.
        app(\App\Services\PaymentService::class)->applyOrphanEvents();
        $this->assertEquals(1, \App\Models\MoneyMovement::where('type', 'payment_received')
            ->distinct()->count('entry_group'));
        $this->assertEquals(0.0, round((float) \App\Models\MoneyMovement::sum('amount'), 2));
    }

    public function test_out_of_order_webhooks_handled_correctly(): void
    {
        Queue::fake();
        $product = Product::factory()->create(['sku' => 'OOO_TEST']);
        $order = $product->orders()->create([
            'sku' => 'OOO_TEST',
            'price' => 1000.00,
            'currency' => 'RUB',
            'status' => OrderStatus::Created,
        ]);

        // Send failed webhook first (later event arriving earlier)
        $this->postJson('/api/webhooks/payment', [
            'event_id' => 'evt_ooo_002',
            'order_id' => $order->id,
            'status' => 'failed',
            'amount' => 1000.00,
            'currency' => 'RUB',
            'created_at' => now()->addSeconds(5)->toIso8601String(),
        ]);

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => OrderStatus::PaymentFailed->value,
        ]);

        // Send paid webhook later (earlier event arriving later)
        $this->postJson('/api/webhooks/payment', [
            'event_id' => 'evt_ooo_001',
            'order_id' => $order->id,
            'status' => 'paid',
            'amount' => 1000.00,
            'currency' => 'RUB',
            'created_at' => now()->toIso8601String(),
        ]);

        // Order stays payment_failed (terminal state, cannot go to paid)
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => OrderStatus::PaymentFailed->value,
        ]);
    }
}
