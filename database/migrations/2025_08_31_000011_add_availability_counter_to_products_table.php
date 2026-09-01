<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Denormalised availability. The storefront is the hottest query in the
            // system and must not aggregate product_keys on every request: with
            // thousands of SKUs and hundreds of thousands of keys, the GROUP BY runs
            // over the whole keys table before LIMIT can discard anything.
            $table->unsignedInteger('available_keys_count')->default(0)->after('currency');
        });

        // Backfill from the source of truth.
        DB::statement("
            UPDATE products SET available_keys_count = COALESCE((
                SELECT COUNT(*) FROM product_keys
                WHERE product_keys.product_id = products.id
                  AND product_keys.status = 'available'
            ), 0)
        ");

        // Partial indexes: the storefront only ever looks at in-stock rows, so the
        // index stays small and the LIMIT can stop early instead of sorting.
        // Keyset pagination orders by id, so id is the trailing column.
        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            // INCLUDE makes the common projection an index-only scan (no heap fetch).
            DB::statement('CREATE INDEX products_storefront_idx ON products (type, id)
                INCLUDE (sku, name, price, currency, available_keys_count)
                WHERE available_keys_count > 0');
            DB::statement('CREATE INDEX products_instock_idx ON products (id)
                INCLUDE (sku, name, type, price, currency, available_keys_count)
                WHERE available_keys_count > 0');
        } elseif ($driver === 'sqlite') {
            DB::statement('CREATE INDEX products_storefront_idx ON products (type, id) WHERE available_keys_count > 0');
            DB::statement('CREATE INDEX products_instock_idx ON products (id) WHERE available_keys_count > 0');
        } else {
            // MySQL has no partial indexes; a plain composite still avoids the join.
            DB::statement('CREATE INDEX products_storefront_idx ON products (type, available_keys_count, id)');
            DB::statement('CREATE INDEX products_instock_idx ON products (available_keys_count, id)');
        }
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS products_storefront_idx');
        DB::statement('DROP INDEX IF EXISTS products_instock_idx');

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('available_keys_count');
        });
    }
};
