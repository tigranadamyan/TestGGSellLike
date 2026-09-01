<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Hard guarantee: a key belongs to at most one order, and an order consumes
        // at most one key. NULLs stay distinct in both Postgres and SQLite, so
        // unclaimed keys are unaffected. This is the last line of defence behind
        // the application-level claim in DeliveryService.
        Schema::table('product_keys', function (Blueprint $table) {
            $table->unique('order_id');
        });
    }

    public function down(): void
    {
        Schema::table('product_keys', function (Blueprint $table) {
            $table->dropUnique(['order_id']);
        });
    }
};
