<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            // `status` already says `voided`; these say when, by whom and why.
            // The invoice keeps its number and its lines: voiding never deletes.
            $table->timestamp('voided_at')->nullable()->after('synced_at');
            // nullOnDelete like `user_id`: the name is lost with the account, the void is not.
            $table->foreignUuid('voided_by')->nullable()->after('voided_at')->constrained('users')->nullOnDelete();
            $table->string('void_reason', 500)->nullable()->after('voided_by');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('voided_by');
            $table->dropColumn(['voided_at', 'void_reason']);
        });
    }
};
