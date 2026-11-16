<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('business_id')->constrained()->cascadeOnDelete();
            $table->string('plan', 64);
            $table->string('status', 32)->default('active');
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();
            // How long a device may keep selling without reaching the server.
            $table->unsignedSmallInteger('offline_grace_days')->default(7);
            $table->unsignedSmallInteger('max_branches')->nullable();
            $table->unsignedSmallInteger('max_users')->nullable();
            $table->unsignedSmallInteger('max_devices')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
