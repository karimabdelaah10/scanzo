<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_variants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('business_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('product_id')->constrained()->cascadeOnDelete();
            // What the QR label encodes.
            $table->string('sku', 64);
            // Printed as a 1D barcode next to the QR, for USB scanners.
            $table->string('barcode', 64)->nullable();
            $table->string('size', 32)->nullable();
            $table->string('color', 32)->nullable();
            $table->decimal('price', 12, 2);
            // Hidden from sellers.
            $table->decimal('cost', 12, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            // The scan lookup. Keep it first among this table's indexes.
            $table->unique(['business_id', 'sku']);
            $table->unique(['business_id', 'barcode']);
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_variants');
    }
};
