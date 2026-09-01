<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\CatalogService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Stage 5: shows why the storefront stays fast at catalogue scale.
 *
 * Seeds thousands of SKUs and hundreds of thousands of keys, then compares the
 * naive join+aggregate against the denormalised counter read, printing the real
 * execution plan for each.
 *
 *   php artisan catalog:benchmark --products=5000 --keys-per-product=40
 */
class CatalogBenchmarkCommand extends Command
{
    protected $signature = 'catalog:benchmark
        {--products=50000 : Number of SKUs to generate}
        {--keys-per-product=30 : Keys generated per SKU}
        {--type=key : Product type used for the filtered query}
        {--seed : Generate data before measuring (destructive: truncates catalogue)}';

    protected $description = 'Compare the naive storefront query against the denormalised one and print execution plans';

    public function handle(CatalogService $catalog): int
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->error('Run this against PostgreSQL — sqlite plans say nothing about production.');

            return self::FAILURE;
        }

        if ($this->option('seed')) {
            $this->seed((int) $this->option('products'), (int) $this->option('keys-per-product'));
        }

        $products = DB::table('products')->count();
        $keys = DB::table('product_keys')->count();
        $this->info("Catalogue: {$products} SKUs, {$keys} keys");
        $this->newLine();

        $type = (string) $this->option('type');

        $naive = "
            SELECT p.id, p.sku, p.name, p.price, COUNT(k.id) AS available
            FROM products p
            LEFT JOIN product_keys k ON k.product_id = p.id AND k.status = 'available'
            WHERE p.type = '{$type}'
            GROUP BY p.id
            HAVING COUNT(k.id) > 0
            ORDER BY p.id
            LIMIT 24
        ";

        $optimised = "
            SELECT id, sku, name, price, currency, available_keys_count
            FROM products
            WHERE type = '{$type}' AND available_keys_count > 0
            ORDER BY id
            LIMIT 24
        ";

        $this->explain('NAIVE — join + GROUP BY over product_keys', $naive);
        $this->explain('OPTIMISED — counter column + partial index', $optimised);

        // Deep page: this is where OFFSET pagination collapses and keyset does not.
        $deepOffsetRows = (int) floor(
            DB::table('products')->where('type', $type)->where('available_keys_count', '>', 0)->count() / 2
        );
        $midId = (int) (DB::table('products')
            ->where('type', $type)
            ->where('available_keys_count', '>', 0)
            ->orderBy('id')
            ->skip($deepOffsetRows)
            ->value('id') ?? 0);

        $deepOffset = "
            SELECT id, sku FROM products
            WHERE type = '{$type}' AND available_keys_count > 0
            ORDER BY id OFFSET {$deepOffsetRows} LIMIT 24
        ";
        $deepKeyset = "
            SELECT id, sku FROM products
            WHERE type = '{$type}' AND available_keys_count > 0 AND id > {$midId}
            ORDER BY id LIMIT 24
        ";

        $this->explain("DEEP PAGE — OFFSET {$deepOffsetRows}", $deepOffset);
        $this->explain('DEEP PAGE — keyset (id > cursor)', $deepKeyset);

        return self::SUCCESS;
    }

    private function explain(string $label, string $sql): void
    {
        $rows = DB::select('EXPLAIN (ANALYZE, BUFFERS, SUMMARY) '.$sql);
        $plan = array_map(fn ($r) => ((array) $r)['QUERY PLAN'], $rows);

        $this->line("<fg=cyan>{$label}</>");
        foreach ($plan as $line) {
            $trimmed = trim($line);
            $highlight = str_starts_with($trimmed, 'Execution Time') || str_contains($line, 'Index Scan using products_');
            $this->line($highlight ? "  <fg=yellow>{$line}</>" : '  '.$line);
        }
        $this->newLine();
    }

    private function seed(int $products, int $keysPer): void
    {
        $this->warn('Truncating catalogue and regenerating…');

        DB::statement('TRUNCATE products, product_keys, orders, deliveries, payment_events, money_movements, supplier_requests RESTART IDENTITY CASCADE');

        $types = ['key', 'giftcard', 'subscription', 'topup'];
        $now = now();

        $bar = $this->output->createProgressBar($products);
        foreach (array_chunk(range(1, $products), 500) as $chunk) {
            $rows = [];
            foreach ($chunk as $i) {
                $rows[] = [
                    'sku' => sprintf('BENCH-%06d', $i),
                    'name' => "Benchmark product {$i}",
                    'type' => $types[$i % count($types)],
                    'price' => 100 + ($i % 5000),
                    'currency' => 'RUB',
                    // Like a real storefront, most SKUs are sold out. That makes
                    // `available_keys_count > 0` selective, which is exactly when a
                    // partial index beats scanning the pk and filtering.
                    'available_keys_count' => $i % 7 === 0 ? $keysPer : 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            DB::table('products')->insert($rows);
            $bar->advance(count($chunk));
        }
        $bar->finish();
        $this->newLine();

        $this->info('Generating keys…');
        $productIds = DB::table('products')->where('available_keys_count', '>', 0)->pluck('id');
        $bar = $this->output->createProgressBar($productIds->count());

        foreach ($productIds->chunk(200) as $chunk) {
            $rows = [];
            foreach ($chunk as $pid) {
                for ($k = 1; $k <= $keysPer; $k++) {
                    $rows[] = [
                        'product_id' => $pid,
                        'code' => "BENCHKEY-{$pid}-{$k}",
                        'status' => 'available',
                        'order_id' => null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }
            DB::table('product_keys')->insert($rows);
            $bar->advance($chunk->count());
        }
        $bar->finish();
        $this->newLine();

        DB::statement('ANALYZE products');
        DB::statement('ANALYZE product_keys');
    }
}
