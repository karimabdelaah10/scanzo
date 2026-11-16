<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An invoice snapshots its own currency, like it snapshots price, name and
     * SKU: the owner changing the shop's currency later must not re-label
     * receipts that were already printed.
     */
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('currency', 3)->default('EGP')->after('total');
        });

        // Existing invoices were all written under whatever the shop uses now:
        // that is the best truth there is for them. One update per business,
        // not per invoice. Soft-deleted businesses are included on purpose.
        DB::table('businesses')->select('id', 'settings')->orderBy('id')->each(function ($business) {
            $settings = is_string($business->settings)
                ? json_decode($business->settings, true)
                : (array) $business->settings;

            $currency = strtoupper((string) ($settings['currency'] ?? 'EGP'));

            DB::table('invoices')
                ->where('business_id', $business->id)
                ->update(['currency' => $currency]);
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('currency');
        });
    }
};
