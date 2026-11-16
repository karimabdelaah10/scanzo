<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_return_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('business_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('sale_return_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('invoice_item_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('variant_id')->nullable()->constrained('product_variants')->nullOnDelete();

            $table->unsignedInteger('quantity');
            // The price it was sold at, not today's.
            $table->decimal('unit_price', 12, 2);
            // False for a damaged piece, which does not go back on the shelf.
            $table->boolean('restock')->default(true);
            $table->string('condition', 32)->nullable();
            $table->timestamps();

            $table->index(['business_id', 'variant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_return_items');
    }
};
