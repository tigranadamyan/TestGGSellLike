<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ProductKeyStatus;
use App\Models\Product;
use App\Models\ProductKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogSearchTest extends TestCase
{
    use RefreshDatabase;

    private function stocked(string $sku, string $name, string $type, int $keys = 2): Product
    {
        $product = Product::factory()->create([
            'sku' => $sku,
            'name' => $name,
            'type' => $type,
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

    public function test_search_matches_name_and_sku(): void
    {
        $this->stocked('KEY-CS2', 'CS2 Prime ключ', 'key');
        $this->stocked('GIFT-PSN', 'PlayStation карта', 'giftcard');

        $byName = $this->getJson('/api/search?q=Prime');
        $byName->assertOk();
        $this->assertSame(['KEY-CS2'], array_column($byName->json('data'), 'sku'));

        $bySku = $this->getJson('/api/search?q=GIFT-PSN');
        $this->assertSame(['GIFT-PSN'], array_column($bySku->json('data'), 'sku'));
    }

    public function test_type_filter_narrows_the_result(): void
    {
        $this->stocked('KEY-A', 'Alpha ключ', 'key');
        $this->stocked('GIFT-A', 'Alpha карта', 'giftcard');

        $response = $this->getJson('/api/search?q=Alpha&type=giftcard');

        $response->assertOk();
        $this->assertSame(['GIFT-A'], array_column($response->json('data'), 'sku'));
        $this->assertSame(1, $response->json('meta.total'));
    }

    public function test_out_of_stock_items_are_hidden_by_default_and_shown_on_request(): void
    {
        $this->stocked('KEY-LIVE', 'Beta ключ', 'key');
        Product::factory()->create([
            'sku' => 'KEY-GONE',
            'name' => 'Beta пропавший',
            'type' => 'key',
            'available_keys_count' => 0,
        ]);

        $default = $this->getJson('/api/search?q=Beta');
        $this->assertSame(['KEY-LIVE'], array_column($default->json('data'), 'sku'));

        $all = $this->getJson('/api/search?q=Beta&in_stock=0');
        $this->assertEqualsCanonicalizing(
            ['KEY-LIVE', 'KEY-GONE'],
            array_column($all->json('data'), 'sku'),
        );
    }

    public function test_query_is_required(): void
    {
        $this->getJson('/api/search')->assertStatus(422);
    }

    public function test_page_size_is_capped(): void
    {
        $this->getJson('/api/search?q=x&per_page=1000')->assertStatus(422);
    }
}
