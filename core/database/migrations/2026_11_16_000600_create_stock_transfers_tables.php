<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A transfer between branches with a receiving step.
     *
     * Sending posts the `transfer_out` at the source. The pieces are then in
     * transit: in no branch's balance, and visible only here. Receiving posts
     * the `transfer_in` at the target for what actually arrived. Anything that
     * did not arrive stays on the line as `quantity_received < quantity_sent`;
     * it is never written off and never added back.
     *
     * Branch foreign keys RESTRICT (unlike stock_movements, which cascade): a
     * transfer is the only record that pieces were once on a van, and a branch
     * delete must not erase it.
     */
    public function up(): void
    {
        Schema::create('stock_transfers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('business_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('from_branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignUuid('to_branch_id')->constrained('branches')->restrictOnDelete();
            // `in_transit` | `received` | `cancelled`.
            $table->string('status', 16);
            $table->string('note')->nullable();

            $table->foreignUuid('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sent_at');
            $table->foreignUuid('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('received_at')->nullable();
            $table->foreignUuid('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();

            // Set at receipt when any line arrived short. Stored so the owner's
            // "shortages" list is an index, not a scan over every line.
            $table->boolean('has_shortage')->default(false);
            $table->timestamps();

            $table->index(['business_id', 'status', 'to_branch_id']);
            $table->index(['business_id', 'from_branch_id', 'sent_at']);
            $table->index(['business_id', 'has_shortage']);
        });

        Schema::create('stock_transfer_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('business_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('transfer_id')->constrained('stock_transfers')->cascadeOnDelete();
            $table->foreignUuid('variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->unsignedInteger('quantity_sent');
            // NULL until the target confirms. Zero is a real answer: nothing arrived.
            $table->unsignedInteger('quantity_received')->nullable();
            $table->timestamps();

            $table->unique(['transfer_id', 'variant_id']);
        });

        $this->convertExistingTransfers();
    }

    /**
     * Under the old model a transfer was a pair of movements that entered the
     * target at once, so every one of them has in fact arrived. Each becomes a
     * `received` transfer, keeping the id the movements already carry as
     * `reference_id`: no movement is rewritten.
     */
    private function convertExistingTransfers(): void
    {
        $now = now();

        DB::table('stock_movements')
            ->where('reference_type', 'stock_transfer')
            ->where('type', 'transfer_out')
            ->orderBy('reference_id')
            ->orderBy('id')
            ->get()
            ->groupBy('reference_id')
            ->each(function ($outs, $transferId) use ($now) {
                $ins = DB::table('stock_movements')
                    ->where('reference_type', 'stock_transfer')
                    ->where('reference_id', $transferId)
                    ->where('type', 'transfer_in')
                    ->get();

                // Always written as a pair, in one transaction. Without the
                // "in" half there is nothing to say arrived, so do not invent it.
                if ($ins->isEmpty()) {
                    return;
                }

                $first = $outs->first();
                $sentAt = $outs->min('occurred_at');

                DB::table('stock_transfers')->insert([
                    'id' => $transferId,
                    'business_id' => $first->business_id,
                    'from_branch_id' => $first->branch_id,
                    'to_branch_id' => $ins->first()->branch_id,
                    'status' => 'received',
                    'note' => $first->note,
                    'sent_by' => $first->user_id,
                    'sent_at' => $sentAt,
                    'received_by' => $ins->first()->user_id,
                    'received_at' => $ins->min('occurred_at'),
                    'has_shortage' => false,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $receivedByVariant = $ins->groupBy('variant_id')->map->sum('quantity');

                foreach ($outs->groupBy('variant_id') as $variantId => $lines) {
                    DB::table('stock_transfer_items')->insert([
                        'id' => (string) \Illuminate\Support\Str::orderedUuid(),
                        'business_id' => $first->business_id,
                        'transfer_id' => $transferId,
                        'variant_id' => $variantId,
                        'quantity_sent' => abs($lines->sum('quantity')),
                        'quantity_received' => (int) ($receivedByVariant[$variantId] ?? 0),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_transfer_items');
        Schema::dropIfExists('stock_transfers');
    }
};
