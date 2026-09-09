<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ProductKeyStatus;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductKey;
use App\Services\CatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A price that moves while an item sits in the cart must be visible before the
 * shopper pays, never discovered afterwards.
 */
class CartPriceChangeTest extends TestCase
{
    use RefreshDatabase;

    private function stocked(string $sku = 'CART_SKU', float $price = 1000.00, int $keys = 3): Product
    {
        $product = Product::factory()->create([
            'sku' => $sku,
            'price' => $price,
            'currency' => 'RUB',
            'available_keys_count' => $keys,
        ]);

        for ($i = 0; $i < $keys; $i++) {
            ProductKey::create([
                'product_id' => $product->id,
                'code' => $sku.'-K'.$i,
                'status' => ProductKeyStatus::Available,
            ]);
        }

        return $product;
    }

    private function reprice(Product $product, string $newPrice): void
    {
        app(CatalogService::class)->updatePrice($product->id, $newPrice);
    }

    public function test_cart_shows_todays_price_and_flags_the_change(): void
    {
        $product = $this->stocked();

        $this->postJson('/cart', ['sku' => $product->sku])->assertOk();

        $this->reprice($product, '1500.00');

        // Re-reading the cart quotes the live figure, not the captured one.
        $payload = $this->postJson('/cart', ['sku' => $product->sku, 'qty' => 1])->json();
        $cartLine = collect($payload['lines'])->firstWhere('sku', $product->sku);

        $this->assertSame('1500.00', $cartLine['price'], 'The cart must quote the current price.');
        $this->assertSame('1000.00', $cartLine['price_at_add'], 'It must also remember what it cost on adding.');
        $this->assertTrue($cartLine['price_changed']);
    }

    public function test_checkout_stops_once_so_the_new_price_is_seen(): void
    {
        $product = $this->stocked();
        $this->postJson('/cart', ['sku' => $product->sku])->assertOk();

        $this->reprice($product, '1500.00');

        $response = $this->postJson('/cart/checkout');

        $response->assertStatus(409)->assertJson(['error' => 'price_changed']);
        $this->assertSame('1500.00', $response->json('changed.0.price'));
        $this->assertSame('1000.00', $response->json('changed.0.price_at_add'));

        // Nothing was charged on the attempt that would have used the stale price.
        $this->assertSame(0, Order::count());
    }

    public function test_confirming_charges_the_new_price_not_the_old_one(): void
    {
        $product = $this->stocked();
        $this->postJson('/cart', ['sku' => $product->sku])->assertOk();
        $this->reprice($product, '1500.00');

        $this->postJson('/cart/checkout')->assertStatus(409);

        // Second click, now that the shopper has seen the new figure.
        $confirmed = $this->postJson('/cart/checkout');

        $confirmed->assertStatus(201);
        $this->assertSame(1, Order::count());
        $this->assertSame('1500.00', Order::firstOrFail()->price);
    }

    public function test_an_unchanged_price_checks_out_in_one_go(): void
    {
        $product = $this->stocked();
        $this->postJson('/cart', ['sku' => $product->sku])->assertOk();

        $this->postJson('/cart/checkout')
            ->assertStatus(201)
            ->assertJsonPath('orders.0.price', '1000.00');
    }

    public function test_quantities_and_totals_track_the_live_price(): void
    {
        $product = $this->stocked();

        $this->postJson('/cart', ['sku' => $product->sku])->assertOk();
        $payload = $this->patchJson("/cart/{$product->sku}", ['qty' => 3])->json();

        $this->assertSame('3000.00', $payload['total']);

        $this->reprice($product, '1500.00');

        $after = $this->getJson('/cart')->baseResponse->getContent();
        $this->assertStringContainsString('4500.00', $after, 'The page must total at the new price.');
    }

    public function test_checkout_reserves_a_key_per_unit(): void
    {
        $product = $this->stocked(keys: 3);

        $this->postJson('/cart', ['sku' => $product->sku])->assertOk();
        $this->patchJson("/cart/{$product->sku}", ['qty' => 2])->assertOk();

        $this->postJson('/cart/checkout')->assertStatus(201);

        $this->assertSame(2, Order::count());
        $this->assertSame(1, $product->fresh()->available_keys_count);
    }

    public function test_a_line_that_sold_out_is_reported_rather_than_charged(): void
    {
        $product = $this->stocked(keys: 1);

        $this->postJson('/cart', ['sku' => $product->sku])->assertOk();
        $this->patchJson("/cart/{$product->sku}", ['qty' => 2])->assertOk();

        $response = $this->postJson('/cart/checkout');

        // One unit was available, so one order exists and the shortfall is named.
        $this->assertSame(1, Order::count());
        $this->assertSame($product->sku, $response->json('sold_out.0.sku'));
    }

    public function test_empty_cart_cannot_be_checked_out(): void
    {
        $this->postJson('/cart/checkout')
            ->assertStatus(422)
            ->assertJson(['error' => 'empty_cart']);
    }
}
