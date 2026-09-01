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

class PaymentWebhookTest extends TestCase
{
    use RefreshDatabase;

    public function test_processes_successful_payment(): void
    {
        Queue::fake();
        $product = Product::factory()->create(['sku' => 'PAY_TEST']);
        $order = $product->orders()->create([
            'sku' => 'PAY_TEST',
            'price' => 1499.00,
            'currency' => 'RUB',
            'status' => OrderStatus::Created,
        ]);

        $response = $this->postJson('/api/webhooks/payment', [
            'event_id' => 'evt_pay_001',
            'order_id' => $order->id,
            'status' => 'paid',
            'amount' => 1499.00,
            'currency' => 'RUB',
            'created_at' => now()->toIso8601String(),
        ]);

        $response->assertOk();

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => OrderStatus::Paid->value,
        ]);

        $this->assertDatabaseHas('payment_events', [
            'event_id' => 'evt_pay_001',
            'status' => 'paid',
        ]);

        $this->assertDatabaseHas('money_movements', [
            'order_id' => $order->id,
            'type' => 'payment_received',
            'amount' => 1499.00,
        ]);

        Queue::assertPushed(DeliverProductJob::class, function ($job) use ($order) {
            return $job->orderId === $order->id;
        });
    }

    public function test_processes_failed_payment(): void
    {
        Queue::fake();
        $product = Product::factory()->create(['sku' => 'FAIL_PAY_TEST']);
        $order = $product->orders()->create([
            'sku' => 'FAIL_PAY_TEST',
            'price' => 500.00,
            'currency' => 'RUB',
            'status' => OrderStatus::Created,
        ]);

        $this->postJson('/api/webhooks/payment', [
            'event_id' => 'evt_fail_001',
            'order_id' => $order->id,
            'status' => 'failed',
            'amount' => 500.00,
            'currency' => 'RUB',
            'created_at' => now()->toIso8601String(),
        ]);

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => OrderStatus::PaymentFailed->value,
        ]);
    }

    public function test_ignores_duplicate_event_id(): void
    {
        Queue::fake();
        $product = Product::factory()->create(['sku' => 'DUP_EVT_TEST']);
        $order = $product->orders()->create([
            'sku' => 'DUP_EVT_TEST',
            'price' => 1000.00,
            'currency' => 'RUB',
            'status' => OrderStatus::Created,
        ]);

        $payload = [
            'event_id' => 'evt_dup_001',
            'order_id' => $order->id,
            'status' => 'paid',
            'amount' => 1000.00,
            'currency' => 'RUB',
            'created_at' => now()->toIso8601String(),
        ];

        $this->postJson('/api/webhooks/payment', $payload)->assertOk();
        $this->postJson('/api/webhooks/payment', $payload)->assertOk();

        $this->assertDatabaseCount('payment_events', 1);

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => OrderStatus::Paid->value,
        ]);
    }

    public function test_returns_duplicate_flag_for_repeated_event(): void
    {
        Queue::fake();
        $product = Product::factory()->create(['sku' => 'DUP_200_TEST']);
        $order = $product->orders()->create([
            'sku' => 'DUP_200_TEST',
            'price' => 1000.00,
            'currency' => 'RUB',
            'status' => OrderStatus::Created,
        ]);

        $payload = [
            'event_id' => 'evt_dup2_001',
            'order_id' => $order->id,
            'status' => 'paid',
            'amount' => 1000.00,
            'currency' => 'RUB',
            'created_at' => now()->toIso8601String(),
        ];

        $response1 = $this->postJson('/api/webhooks/payment', $payload);
        $response1->assertOk()->assertJson(['duplicate' => false]);

        $response2 = $this->postJson('/api/webhooks/payment', $payload);
        $response2->assertOk()->assertJson(['duplicate' => true]);
    }
}
