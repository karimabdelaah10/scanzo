<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            // An amount, never a percentage: 20% off a price that changes later
            // must stay the amount the customer was actually given. Zero (not
            // null) on every old row, so `line_total = unit_price * quantity -
            // discount_amount` holds for all of them with no special case.
            $table->decimal('discount_amount', 12, 2)->default(0)->after('unit_price');
        });

        Schema::table('sale_return_items', function (Blueprint $table) {
            // What was actually handed back for this line: the share of what the
            // customer PAID, not of the list price. Needed because
            // unit_price * quantity is wrong for a discounted line.
            $table->decimal('amount', 12, 2)->default(0)->after('unit_price');
        });

        // Old returns had no line discounts, so list price * quantity was what
        // they refunded; keep that figure as the amount.
        DB::statement('UPDATE sale_return_items SET amount = unit_price * quantity');
    }

    public function down(): void
    {
        Schema::table('sale_return_items', fn (Blueprint $table) => $table->dropColumn('amount'));
        Schema::table('invoice_items', fn (Blueprint $table) => $table->dropColumn('discount_amount'));
    }
};
