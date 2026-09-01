<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_requests', function (Blueprint $table) {
            $table->id();
            $table->string('request_id')->unique();
            $table->string('supplier');
            $table->string('sku');
            $table->string('status')->default('pending');
            $table->string('code')->nullable();
            $table->text('error')->nullable();
            $table->jsonb('response_payload')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_requests');
    }
};
