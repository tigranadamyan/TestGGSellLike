<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('money_movements', function (Blueprint $table) {
            // Double entry: every business event writes a group of rows whose
            // signed amounts sum to exactly zero. `account` says which bucket the
            // money sits in, `entry_group` ties the two halves together.
            $table->string('account')->after('order_id');
            $table->uuid('entry_group')->after('account');

            $table->index(['entry_group']);
            $table->index(['account']);
            $table->index(['order_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::table('money_movements', function (Blueprint $table) {
            $table->dropIndex(['order_id', 'type']);
            $table->dropIndex(['account']);
            $table->dropIndex(['entry_group']);
            $table->dropColumn(['account', 'entry_group']);
        });
    }
};
