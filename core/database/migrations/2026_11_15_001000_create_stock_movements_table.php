<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Stock is a ledger, not a number. The balance of a variant in a branch is
     * the sum of its movements, which is what lets two offline devices both
     * record a valid sale instead of one of them being silently lost.
     *
     * Nothing may change stock except by inserting a row here.
     */
    public function up(): void
    {
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('business_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('variant_id')->constrained('product_variants')->cascadeOnDelete();
            // Signed: positive is in, negative is out.
            $table->integer('quantity');
            $table->string('type', 32);
            // The invoice or return that caused it.
            $table->nullableUuidMorphs('reference');
            $table->foreignUuid('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('device_id')->nullable()->constrained()->nullOnDelete();
            $table->string('note')->nullable();
            // Device clock.
            $table->timestamp('occurred_at');
            $table->timestamp('created_at')->nullable();

            $table->index(['business_id', 'branch_id', 'variant_id'], 'stock_balance_index');
            $table->index(['business_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};
