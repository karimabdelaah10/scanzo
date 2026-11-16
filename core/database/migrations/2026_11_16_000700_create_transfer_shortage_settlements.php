<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A shortage found later can be settled: it MOVES, it is not erased.
     *
     * One row per settlement says which branch the pieces really went into,
     * how many, why, who and when. The stock movement it caused is a normal
     * ledger row. `quantity_settled` on the line is the running total of those
     * rows, kept so the open shortage is a column and not a sum per screen.
     */
    public function up(): void
    {
        Schema::table('stock_transfer_items', function (Blueprint $table) {
            $table->unsignedInteger('quantity_settled')->default(0)->after('quantity_received');
        });

        Schema::create('stock_transfer_settlements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('business_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('transfer_id')->constrained('stock_transfers')->cascadeOnDelete();
            $table->foreignUuid('variant_id')->constrained('product_variants')->restrictOnDelete();
            // Where the pieces actually are: the transfer's target or its source.
            $table->foreignUuid('branch_id')->constrained('branches')->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->string('reason', 500);
            $table->foreignUuid('settled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('settled_at');
            $table->timestamps();

            $table->index(['transfer_id', 'variant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_transfer_settlements');
        Schema::table('stock_transfer_items', function (Blueprint $table) {
            $table->dropColumn('quantity_settled');
        });
    }
};
