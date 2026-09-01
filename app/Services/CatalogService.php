<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ProductKeyStatus;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

/**
 * Storefront reads and maintenance of the denormalised availability counter.
 *
 * `products.available_keys_count` is a cache of
 * `COUNT(product_keys WHERE status='available')`. It is kept correct on the fast
 * path by atomic increments inside the same transaction as the key change, and
 * verified (and repairable) by `recount()` / `drift()`.
 */
class CatalogService
{
    /** Page size cap, so a caller cannot ask for the whole catalogue. */
    public const MAX_PER_PAGE = 100;

    /**
     * Hot storefront query. Deliberately touches only `products`:
     * no join, no GROUP BY, and keyset pagination instead of OFFSET so the cost
     * stays flat no matter how deep the page is.
     *
     * @return array{items: list<array<string,mixed>>, next_cursor: int|null}
     */
    public function storefront(?string $type = null, bool $inStockOnly = true, int $perPage = 24, ?int $afterId = null): array
    {
        $perPage = max(1, min($perPage, self::MAX_PER_PAGE));

        $query = Product::query()
            ->select(['id', 'sku', 'name', 'type', 'price', 'currency', 'available_keys_count'])
            ->orderBy('id')
            ->limit($perPage + 1);

        if ($inStockOnly) {
            $query->where('available_keys_count', '>', 0);
        }

        if ($type !== null) {
            $query->where('type', $type);
        }

        // Keyset: "everything after the last id I saw" — an index range scan that
        // stops after $perPage rows, unlike OFFSET which must walk and discard.
        if ($afterId !== null) {
            $query->where('id', '>', $afterId);
        }

        $rows = $query->get();

        $hasMore = $rows->count() > $perPage;
        $items = $rows->take($perPage);

        return [
            'items' => array_values($items->map(fn (Product $p) => [
                'sku' => $p->sku,
                'name' => $p->name,
                'type' => $p->type,
                'price' => $p->price,
                'currency' => $p->currency,
                'in_stock' => $p->available_keys_count > 0,
                'available' => $p->available_keys_count,
            ])->all()),
            'next_cursor' => $hasMore ? $items->last()->id : null,
        ];
    }

    /**
     * Atomically adjust the cached counter. Runs as a single UPDATE so concurrent
     * reservations cannot lose an increment, and must be called inside the same
     * transaction as the key status change.
     */
    public function adjustAvailability(int $productId, int $delta): void
    {
        if ($delta === 0) {
            return;
        }

        $query = DB::table('products')->where('id', $productId);

        // increment/decrement issue a single atomic UPDATE ... SET c = c ± n.
        if ($delta > 0) {
            $query->increment('available_keys_count', $delta, ['updated_at' => now()]);
        } else {
            $query->decrement('available_keys_count', -$delta, ['updated_at' => now()]);
        }
    }

    /**
     * Products whose cached counter disagrees with the keys table.
     *
     * @return list<array{product_id: int, cached: int, actual: int}>
     */
    public function drift(): array
    {
        $rows = DB::table('products')
            ->leftJoin('product_keys', function ($join) {
                $join->on('product_keys.product_id', '=', 'products.id')
                    ->where('product_keys.status', '=', ProductKeyStatus::Available->value);
            })
            ->groupBy('products.id', 'products.available_keys_count')
            ->havingRaw('COUNT(product_keys.id) <> products.available_keys_count')
            ->select([
                'products.id as product_id',
                'products.available_keys_count as cached',
                DB::raw('COUNT(product_keys.id) as actual'),
            ])
            ->get()
            ->all();

        return array_values(array_map(fn ($r) => [
            'product_id' => (int) $r->product_id,
            'cached' => (int) $r->cached,
            'actual' => (int) $r->actual,
        ], $rows));
    }

    /** Rebuild every counter from the keys table. Returns rows repaired. */
    public function recount(): int
    {
        $drifted = $this->drift();

        foreach ($drifted as $row) {
            DB::table('products')
                ->where('id', $row['product_id'])
                ->update(['available_keys_count' => $row['actual']]);
        }

        return count($drifted);
    }
}
