<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ProductKeyStatus;
use App\Models\Product;
use App\Models\ProductKey;
use App\Services\CatalogService;
use App\Services\ReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Stage 5: the storefront must stay correct while it is denormalised.
 *
 * `products.available_keys_count` is a cache of the keys table. These tests pin
 * the two things that matter: it never lies, and it is repairable when it does.
 */
class CatalogTest extends TestCase
{
    use RefreshDatabase;

    private function product(string $sku, string $type, int $keys, float $price = 1000): Product
    {
        $product = Product::factory()->create(['sku' => $sku, 'type' => $type, 'price' => $price]);

        for ($i = 1; $i <= $keys; $i++) {
            ProductKey::create([
                'product_id' => $product->id,
                'code' => "{$sku}_K{$i}",
                'status' => ProductKeyStatus::Available,
            ]);
        }

        app(CatalogService::class)->recount();

        return $product->fresh();
    }

    public function test_storefront_reports_availability_without_touching_the_keys_table(): void
    {
        $this->product('CAT_IN', 'key', 3);
        $this->product('CAT_OUT', 'key', 0);

        $queries = [];
        DB::listen(function ($q) use (&$queries) {
            $queries[] = $q->sql;
        });

        $response = $this->getJson('/api/catalog?in_stock=0')->assertOk();

        // The hot read must not join or aggregate product_keys.
        $selects = array_filter($queries, fn ($sql) => str_starts_with(strtolower($sql), 'select'));
        foreach ($selects as $sql) {
            $this->assertStringNotContainsString('product_keys', $sql, 'storefront must not read product_keys');
        }

        $bySku = collect($response->json('data'))->keyBy('sku');
        $this->assertTrue($bySku['CAT_IN']['in_stock']);
        $this->assertEquals(3, $bySku['CAT_IN']['available']);
        $this->assertFalse($bySku['CAT_OUT']['in_stock']);
    }

    public function test_in_stock_filter_and_type_filter(): void
    {
        $this->product('CAT_KEY_IN', 'key', 2);
        $this->product('CAT_KEY_OUT', 'key', 0);
        $this->product('CAT_GIFT_IN', 'giftcard', 5);

        $skus = collect($this->getJson('/api/catalog')->assertOk()->json('data'))->pluck('sku');
        $this->assertEqualsCanonicalizing(['CAT_KEY_IN', 'CAT_GIFT_IN'], $skus->all());

        $skus = collect($this->getJson('/api/catalog?type=key')->assertOk()->json('data'))->pluck('sku');
        $this->assertEquals(['CAT_KEY_IN'], $skus->all());
    }

    public function test_keyset_pagination_walks_the_catalogue_without_gaps_or_repeats(): void
    {
        for ($i = 1; $i <= 25; $i++) {
            $this->product(sprintf('PAGE_%03d', $i), 'key', 1);
        }

        $seen = [];
        $cursor = null;
        $pages = 0;

        do {
            $url = '/api/catalog?per_page=10'.($cursor !== null ? "&after={$cursor}" : '');
            $body = $this->getJson($url)->assertOk()->json();

            foreach ($body['data'] as $row) {
                $seen[] = $row['sku'];
            }

            $cursor = $body['meta']['next_cursor'];
            $pages++;
        } while ($cursor !== null && $pages < 10);

        $this->assertCount(25, $seen);
        $this->assertCount(25, array_unique($seen), 'no SKU may appear on two pages');
        $this->assertEquals(3, $pages);
    }

    public function test_counter_is_decremented_when_a_key_is_reserved(): void
    {
        $product = $this->product('CAT_RESERVE', 'key', 4);
        $this->assertEquals(4, $product->available_keys_count);

        $order = $product->orders()->create([
            'sku' => 'CAT_RESERVE',
            'price' => 1000,
            'currency' => 'RUB',
            'status' => \App\Enums\OrderStatus::Paid,
        ]);

        app(\App\Services\DeliveryService::class)->deliver($order);

        $this->assertEquals(3, $product->fresh()->available_keys_count);
        $this->assertSame([], app(CatalogService::class)->drift(), 'counter must match the keys table');
    }

    public function test_counter_stays_correct_across_many_deliveries(): void
    {
        $product = $this->product('CAT_MANY', 'key', 10);

        for ($i = 0; $i < 6; $i++) {
            $order = $product->orders()->create([
                'sku' => 'CAT_MANY',
                'price' => 1000,
                'currency' => 'RUB',
                'status' => \App\Enums\OrderStatus::Paid,
            ]);
            app(\App\Services\DeliveryService::class)->deliver($order);
        }

        $this->assertEquals(4, $product->fresh()->available_keys_count);
        $this->assertSame([], app(CatalogService::class)->drift());
    }

    public function test_drift_is_detected_and_repaired_by_reconciliation(): void
    {
        $product = $this->product('CAT_DRIFT', 'key', 5);

        // Simulate a counter that got out of sync (e.g. keys restocked by a bulk
        // import that bypassed the application).
        DB::table('products')->where('id', $product->id)->update(['available_keys_count' => 99]);

        $drift = app(CatalogService::class)->drift();
        $this->assertCount(1, $drift);
        $this->assertEquals(['product_id' => $product->id, 'cached' => 99, 'actual' => 5], $drift[0]);

        $results = app(ReconciliationService::class)->reconcile();

        $this->assertCount(1, $results['catalog_drift']);
        $this->assertEquals(5, $product->fresh()->available_keys_count, 'reconciliation repairs the cache');
        $this->assertSame([], app(CatalogService::class)->drift());
    }

    public function test_per_page_is_capped(): void
    {
        $this->getJson('/api/catalog?per_page=5000')->assertStatus(422);
    }
}
