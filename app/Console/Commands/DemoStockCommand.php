<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ProductKeyStatus;
use App\Models\Product;
use App\Models\ProductKey;
use App\Models\Reservation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Puts a product back into a known state so the live-storefront and last-unit
 * demos can be run again from scratch.
 */
class DemoStockCommand extends Command
{
    protected $signature = 'demo:stock
                            {sku : Product SKU}
                            {count=1 : How many keys should be on sale}';

    protected $description = 'Set a product to an exact number of available keys (demo helper)';

    public function handle(): int
    {
        $sku = (string) $this->argument('sku');
        $count = max(0, (int) $this->argument('count'));

        $product = Product::where('sku', $sku)->first();

        if (! $product) {
            $this->error("Unknown SKU: {$sku}");

            return self::FAILURE;
        }

        DB::transaction(function () use ($product, $count) {
            // Drop live holds so their keys are not counted twice.
            Reservation::where('product_id', $product->id)
                ->whereNull('cancelled_at')
                ->update(['cancelled_at' => now()]);

            // Park everything, then hand back exactly the requested number.
            ProductKey::where('product_id', $product->id)
                ->update(['status' => ProductKeyStatus::Issued->value, 'order_id' => null]);

            $pool = ProductKey::where('product_id', $product->id)
                ->orderBy('id')
                ->limit($count)
                ->pluck('id');

            // Top up if the product was never seeded with enough keys.
            for ($i = $pool->count(); $i < $count; $i++) {
                $pool->push(ProductKey::create([
                    'product_id' => $product->id,
                    'code' => strtoupper(Str::random(4).'-'.Str::random(4).'-'.Str::random(4)),
                    'status' => ProductKeyStatus::Issued,
                ])->id);
            }

            ProductKey::whereIn('id', $pool)
                ->update(['status' => ProductKeyStatus::Available->value, 'order_id' => null]);

            $product->update(['available_keys_count' => $count]);
        });

        $this->info("{$sku}: {$count} key(s) on sale.");

        return self::SUCCESS;
    }
}
