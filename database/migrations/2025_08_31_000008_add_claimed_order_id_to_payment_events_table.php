<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_events', function (Blueprint $table) {
            // The order id the payment system claims this event belongs to. Kept
            // separate from order_id (a real FK) so a webhook that arrives BEFORE
            // its order can still be stored instead of rejected and lost.
            $table->unsignedBigInteger('claimed_order_id')->nullable()->after('order_id');
            $table->index(['claimed_order_id', 'processed_at']);
        });
    }

    public function down(): void
    {
        Schema::table('payment_events', function (Blueprint $table) {
            $table->dropIndex(['claimed_order_id', 'processed_at']);
            $table->dropColumn('claimed_order_id');
        });
    }
};
