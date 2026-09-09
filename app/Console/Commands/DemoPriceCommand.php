<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\CatalogService;
use Illuminate\Console\Command;

/**
 * Moves a price and broadcasts it, so the "price changed while it sat in the
 * cart" behaviour can be demonstrated without waiting for a real repricing.
 */
class DemoPriceCommand extends Command
{
    protected $signature = 'demo:price {sku : Product SKU} {price : New price, e.g. 1490.00}';

    protected $description = 'Change a product price and push it to every open page (demo helper)';

    public function handle(CatalogService $catalog): int
    {
        $product = Product::where('sku', $this->argument('sku'))->first();

        if (! $product) {
            $this->error("Unknown SKU: {$this->argument('sku')}");

            return self::FAILURE;
        }

        $old = (string) $product->price;
        $new = number_format((float) $this->argument('price'), 2, '.', '');

        $catalog->updatePrice($product->id, $new);

        $this->info("{$product->sku}: {$old} → {$new} {$product->currency}");

        return self::SUCCESS;
    }
}
