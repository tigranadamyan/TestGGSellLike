<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\ProductKeyStatus;
use App\Jobs\DeliverProductJob;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductKey;
use App\Services\DeliveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeliveryJobRetryTest extends TestCase
{
    use RefreshDatabase;

    public function test_delivery_job_is_idempotent(): void
    {
        $product = Product::factory()->create(['sku' => 'JOB_TEST']);

        ProductKey::create([
            'product_id' => $product->id,
            'code' => 'JOB_KEY_001',
            'status' => ProductKeyStatus::Available,
        ]);

        $order = $product->orders()->create([
            'sku' => 'JOB_TEST',
            'price' => 1000.00,
            'currency' => 'RUB',
            'status' => OrderStatus::Paid,
        ]);

        $deliveryService = app(DeliveryService::class);

        // Run the delivery 3 times
        for ($i = 0; $i < 3; $i++) {
            $job = new DeliverProductJob($order->id);
            $job->handle($deliveryService);
        }

        $order->refresh();
        $this->assertEquals(OrderStatus::Delivered->value, $order->status->value);

        $this->assertDatabaseCount('deliveries', 1);

        $this->assertDatabaseCount('product_keys', 1);

        $this->assertDatabaseHas('product_keys', [
            'product_id' => $product->id,
            'status' => ProductKeyStatus::Issued->value,
        ]);
    }
}
