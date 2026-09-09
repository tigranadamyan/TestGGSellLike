<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->onDelete('cascade');
            $table->foreignId('product_id')->constrained()->onDelete('cascade');
            $table->foreignId('product_key_id')->nullable()->constrained('product_keys')->onDelete('set null');
            $table->timestamp('expires_at');
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
        });

        // Only one active (non-cancelled) reservation per product at a time
        // PostgreSQL partial unique index: WHERE cancelled_at IS NULL
        DB::statement('CREATE UNIQUE INDEX reservations_product_active_idx ON reservations (product_id) WHERE cancelled_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('reservations');
    }
};
