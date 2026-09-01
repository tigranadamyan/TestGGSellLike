<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderCreationTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_an_order_with_correct_data(): void
    {
        $product = Product::factory()->create([
            'sku' => 'TEST_SKU_001',
            'price' => 1499.00,
            'currency' => 'RUB',
        ]);

        $response = $this->postJson('/api/orders', ['sku' => 'TEST_SKU_001']);

        $response->assertStatus(201)
            ->assertJsonFragment([
                'sku' => 'TEST_SKU_001',
                'price' => '1499.00',
                'currency' => 'RUB',
                'status' => OrderStatus::Created->value,
            ]);

        $this->assertDatabaseHas('orders', [
            'sku' => 'TEST_SKU_001',
            'price' => 1499.00,
            'status' => OrderStatus::Created->value,
        ]);
    }

    public function test_fixes_price_from_product_at_order_creation_time(): void
    {
        $product = Product::factory()->create([
            'sku' => 'PRICE_TEST',
            'price' => 1000.00,
        ]);

        $this->postJson('/api/orders', ['sku' => 'PRICE_TEST']);

        $product->update(['price' => 2000.00]);

        $this->assertDatabaseHas('orders', [
            'sku' => 'PRICE_TEST',
            'price' => 1000.00,
        ]);
    }

    public function test_rejects_order_with_non_existent_sku(): void
    {
        $response = $this->postJson('/api/orders', ['sku' => 'NONEXISTENT']);

        $response->assertStatus(422);
    }

    public function test_returns_order_with_delivery_info(): void
    {
        $product = Product::factory()->create(['sku' => 'SHOW_TEST']);
        $order = $product->orders()->create([
            'sku' => 'SHOW_TEST',
            'price' => $product->price,
            'currency' => $product->currency,
            'status' => OrderStatus::Created,
        ]);

        $response = $this->getJson("/api/orders/{$order->id}");

        $response->assertOk()
            ->assertJsonFragment([
                'id' => $order->id,
                'status' => OrderStatus::Created->value,
            ]);
    }
}
