<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Reports read a range of dates across every branch of the business.
     *
     * The existing (business_id, branch_id, issued_at) index serves a range
     * only when a branch is named: without branch_id, MySQL can use just its
     * business_id prefix and then reads every invoice the business ever wrote
     * to test the dates. The owner's "all branches" month is exactly that case.
     * (business_id, issued_at) lets it seek straight to the range.
     *
     * sale_returns gets the same one for the returns figure beside it.
     */
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->index(['business_id', 'issued_at']);
        });

        Schema::table('sale_returns', function (Blueprint $table) {
            $table->index(['business_id', 'issued_at']);
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex(['business_id', 'issued_at']);
        });

        Schema::table('sale_returns', function (Blueprint $table) {
            $table->dropIndex(['business_id', 'issued_at']);
        });
    }
};
