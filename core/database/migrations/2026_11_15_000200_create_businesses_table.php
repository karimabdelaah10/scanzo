<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('businesses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('slug', 120)->unique();
            $table->string('status', 32)->default('trial');
            $table->string('phone', 32)->nullable();
            $table->string('tax_number', 64)->nullable();
            // Everything a business can change without a migration: logo,
            // receipt header and footer, label size, currency, return window,
            // who may issue returns, scan behaviour.
            $table->json('settings')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('businesses');
    }
};
