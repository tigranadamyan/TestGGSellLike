<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('sku');
            $table->decimal('price', 12, 2);
            $table->string('currency', 3)->default('RUB');
            $table->string('status')->default('created');
            $table->timestamps();

            $table->index('status');
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
