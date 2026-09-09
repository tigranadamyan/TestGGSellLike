<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * A reservation holds one specific key, not a whole product.
     *
     * The original index was UNIQUE(product_id) WHERE cancelled_at IS NULL, which
     * allowed only one active reservation per SKU across the entire shop: the
     * second buyer of a product with six keys in stock hit a unique violation.
     * It also ignored expiry, so an expired-but-not-yet-cancelled row kept the
     * product unbuyable.
     */
    public function up(): void
    {
        // Rows written under the old scheme hold no key at all, yet still counted
        // as active and kept their product unbuyable. They describe nothing.
        DB::table('reservations')
            ->whereNull('cancelled_at')
            ->whereNull('product_key_id')
            ->update(['cancelled_at' => now()]);

        DB::statement('DROP INDEX IF EXISTS reservations_product_active_idx');

        // One live reservation per key: this is what makes the last-unit race honest.
        DB::statement('CREATE UNIQUE INDEX reservations_key_active_idx ON reservations (product_key_id) WHERE cancelled_at IS NULL');

        // One live reservation per order: a retry cannot burn a second key.
        DB::statement('CREATE UNIQUE INDEX reservations_order_active_idx ON reservations (order_id) WHERE cancelled_at IS NULL');

        // The expiry sweep scans exactly this.
        DB::statement('CREATE INDEX reservations_expiry_idx ON reservations (expires_at) WHERE cancelled_at IS NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS reservations_key_active_idx');
        DB::statement('DROP INDEX IF EXISTS reservations_order_active_idx');
        DB::statement('DROP INDEX IF EXISTS reservations_expiry_idx');
        DB::statement('CREATE UNIQUE INDEX reservations_product_active_idx ON reservations (product_id) WHERE cancelled_at IS NULL');
    }
};
