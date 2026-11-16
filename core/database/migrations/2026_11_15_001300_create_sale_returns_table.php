<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Named `sale_returns`, not `returns`: `returns` is a MySQL reserved word
     * and `Return` is not a legal PHP class name.
     *
     * An exchange is a return plus a new invoice, linked by
     * `exchange_invoice_id` — not a third kind of transaction.
     */
    public function up(): void
    {
        Schema::create('sale_returns', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('business_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('device_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained()->nullOnDelete();
            // Null only where the business allows returns without an invoice.
            $table->foreignUuid('invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('exchange_invoice_id')->nullable()->constrained('invoices')->nullOnDelete();

            $table->string('number', 32);
            $table->decimal('refund_amount', 12, 2);
            $table->string('refund_method', 32)->default('cash');
            $table->string('reason')->nullable();
            $table->timestamp('issued_at');
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['business_id', 'number']);
            $table->index(['business_id', 'branch_id', 'issued_at']);
            $table->index(['business_id', 'synced_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_returns');
    }
};
