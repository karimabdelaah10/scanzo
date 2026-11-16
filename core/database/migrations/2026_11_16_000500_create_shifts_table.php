<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A till shift: the drawer from the float in the morning to the count at
     * night. It answers one question — expected cash against counted cash.
     *
     * Like an invoice, the id and both timestamps come from the device, so a
     * shift opened and closed offline can be synced later without being
     * re-dated: `opened_at` / `closed_at` are the device's clock, `synced_at`
     * is when the server heard.
     */
    public function up(): void
    {
        Schema::create('shifts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('business_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('device_id')->constrained()->cascadeOnDelete();
            // Who opened it. Nulled, not cascaded, with the account: the
            // difference outlives the person.
            $table->foreignUuid('user_id')->nullable()->constrained()->nullOnDelete();
            // A manager closing a colleague's forgotten shift; null for an
            // automatic close (see close_kind).
            $table->foreignUuid('closed_by')->nullable()->constrained('users')->nullOnDelete();

            $table->decimal('opening_float', 12, 2);
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();

            // Written once, at close, and never recomputed: a later void or a
            // late-syncing invoice must not rewrite last month's difference.
            $table->decimal('expected_cash', 12, 2)->nullable();
            // Null on an automatic close: nobody counted.
            $table->decimal('counted_cash', 12, 2)->nullable();
            // counted - expected, SIGNED: negative is a shortage, positive a surplus.
            $table->decimal('difference', 12, 2)->nullable();
            // `counted` or `auto`.
            $table->string('close_kind', 16)->nullable();
            $table->string('note', 500)->nullable();

            // Equals device_id while the shift is open and NULL once it is
            // closed. A unique index over it is "one open shift per device",
            // enforced by the database, so two phones opening at once cannot
            // both win. (NULLs do not collide in a unique index.)
            $table->uuid('open_device_id')->nullable()->unique();

            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'branch_id', 'opened_at']);
            $table->index(['business_id', 'user_id', 'opened_at']);
            $table->index(['device_id', 'opened_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shifts');
    }
};
