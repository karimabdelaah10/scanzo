<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Each row keeps a snapshot of what was sold. Changing a product's price or
     * name later must not rewrite history, and a deleted variant must not break
     * an old receipt — so nothing here is read back off the catalogue.
     */
    public function up(): void
    {
        Schema::create('invoice_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('business_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('variant_id')->nullable()->constrained('product_variants')->nullOnDelete();

            // Snapshot at sale time.
            $table->string('product_name');
            $table->string('variant_label')->nullable();
            $table->string('sku', 64);
            $table->decimal('unit_price', 12, 2);
            $table->unsignedInteger('quantity');
            $table->decimal('line_total', 12, 2);

            // How much of this line has already come back. A return may never
            // push this above `quantity`.
            $table->unsignedInteger('returned_quantity')->default(0);
            $table->timestamps();

            $table->index(['business_id', 'variant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_items');
    }
};
