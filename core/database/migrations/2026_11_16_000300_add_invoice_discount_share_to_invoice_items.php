<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            // This line's share of the invoice-level discount, worked out when
            // the sale was made and never again. What the customer PAID for the
            // line is line_total - invoice_discount_share. Zero, not null, on
            // an invoice with no invoice discount.
            $table->decimal('invoice_discount_share', 12, 2)->default(0)->after('discount_amount');
        });

        // Old invoices: spread the stored invoice discount over the stored
        // lines, the way CreateInvoice does now (cumulative shares, so they add
        // up to the discount exactly). The arithmetic is written out here
        // rather than calling App\Support\Money: a migration must keep doing
        // what it did when it was written, even if that class changes.
        //
        // Invoices with no invoice discount are not touched at all — the column
        // default of 0 is already right for them.
        DB::table('invoices')
            ->where('discount_amount', '>', 0)
            ->select('id', 'discount_amount')
            ->orderBy('id')
            ->chunk(200, function ($invoices) {
                foreach ($invoices as $invoice) {
                    $items = DB::table('invoice_items')
                        ->where('invoice_id', $invoice->id)
                        ->orderBy('id')
                        ->get(['id', 'line_total'])
                        ->values();

                    $weights = $items->map(fn ($i) => (int) round($i->line_total * 100))->all();
                    $sum = array_sum($weights);
                    $discount = min((int) round($invoice->discount_amount * 100), $sum);

                    if ($sum <= 0 || $discount <= 0) {
                        continue;
                    }

                    $running = 0;
                    $previous = 0;

                    foreach ($items as $n => $item) {
                        $running += $weights[$n];
                        $cumulative = intdiv($discount * $running * 2 + $sum, 2 * $sum);

                        DB::table('invoice_items')->where('id', $item->id)->update([
                            'invoice_discount_share' => number_format(($cumulative - $previous) / 100, 2, '.', ''),
                        ]);

                        $previous = $cumulative;
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('invoice_items', fn (Blueprint $table) => $table->dropColumn('invoice_discount_share'));
    }
};
