<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Only create fulltext index on PostgreSQL (SQLite doesn't support GIN)
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("CREATE INDEX products_search_idx ON products USING GIN (to_tsvector('russian', name || ' ' || sku))");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS products_search_idx');
        }
    }
};
