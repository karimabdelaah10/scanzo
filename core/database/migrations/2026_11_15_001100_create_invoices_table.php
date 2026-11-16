<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('business_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('device_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained()->nullOnDelete();
            // Device code plus a counter local to that device: A1-000123.
            $table->string('number', 32);
            $table->string('customer_name')->nullable();
            $table->string('customer_phone', 32)->nullable();
            $table->decimal('subtotal', 12, 2);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('total', 12, 2);
            $table->string('payment_method', 32)->default('cash');
            $table->string('status', 32)->default('completed');
            // Device clock: this is what the receipt shows.
            $table->timestamp('issued_at');
            // Null means it is still only on the device.
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['business_id', 'number']);
            $table->index(['business_id', 'branch_id', 'issued_at']);
            $table->index(['business_id', 'synced_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
