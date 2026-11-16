<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Product;
use App\Models\Shift;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use Database\Seeders\DemoVolumeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DemoVolumeSeederTest extends TestCase
{
    use RefreshDatabase;

    private function seedAsLocal(): void
    {
        $this->app['env'] = 'local';
        $this->seed(DemoVolumeSeeder::class);
        $this->app['env'] = 'testing';
    }

    /** Raw counts: the tenant scope needs a business in context, and the seeder has finished with it. */
    private function counts(): array
    {
        return [
            'products' => DB::table('products')->count(),
            'variants' => DB::table('product_variants')->count(),
            'invoices' => DB::table('invoices')->count(),
            'returns' => DB::table('sale_returns')->count(),
            'shifts' => DB::table('shifts')->count(),
            'transfers' => DB::table('stock_transfers')->count(),
            'movements' => DB::table('stock_movements')->count(),
            'users' => DB::table('users')->count(),
        ];
    }

    public function test_it_refuses_to_run_outside_local(): void
    {
        $this->assertSame('testing', app()->environment());

        try {
            $this->seed(DemoVolumeSeeder::class);
            $this->fail('the seeder ran in the testing environment');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('local', $e->getMessage());
        }

        $this->assertSame(0, DB::table('products')->count());
    }

    public function test_running_it_twice_does_not_double_the_data(): void
    {
        $this->seedAsLocal();
        $first = $this->counts();

        $this->seedAsLocal();

        $this->assertSame($first, $this->counts());
    }

    public function test_it_makes_the_volume_and_the_stories_the_brief_asks_for(): void
    {
        $this->seedAsLocal();
        $c = $this->counts();

        $this->assertGreaterThanOrEqual(50, $c['products']);
        $this->assertLessThanOrEqual(70, $c['products']);
        $this->assertGreaterThanOrEqual(30, $c['invoices']);
        $this->assertGreaterThanOrEqual(3, $c['returns']);
        $this->assertGreaterThanOrEqual(5, DB::table('shifts')->whereNotNull('closed_at')->count());
        $this->assertGreaterThanOrEqual(1, DB::table('shifts')->where('close_kind', 'auto')->count(), 'at least one auto-closed shift');

        $counted = DB::table('shifts')->where('close_kind', '!=', 'auto')->whereNotNull('closed_at');
        $this->assertGreaterThan(0, (clone $counted)->where('difference', '<', 0)->count(), 'a short drawer');
        $this->assertGreaterThan(0, (clone $counted)->where('difference', '>', 0)->count(), 'an over drawer');
        $this->assertGreaterThan(0, (clone $counted)->where('difference', 0)->count(), 'a drawer that balanced');

        $this->assertGreaterThanOrEqual(2, DB::table('invoices')->whereNotNull('voided_at')->count());
        $this->assertGreaterThan(0, DB::table('invoices')->where('discount_amount', '>', 0)->count(), 'an invoice-level discount');
        $this->assertGreaterThan(0, DB::table('invoice_items')->where('discount_amount', '>', 0)->count(), 'a line discount');

        $this->assertSame(1, DB::table('stock_transfers')->where('status', 'in_transit')->count());
        $this->assertSame(1, DB::table('stock_transfers')->where('status', 'received')->where('has_shortage', true)->count());
        $this->assertSame(1, DB::table('invoices')->where('discount_amount', '>', 0)->whereExists(
            fn ($q) => $q->from('invoice_items')->whereColumn('invoice_items.invoice_id', 'invoices.id')->where('invoice_items.discount_amount', '>', 0)
                ->whereExists(fn ($r) => $r->from('sale_return_items')->whereColumn('sale_return_items.invoice_item_id', 'invoice_items.id')),
        )->count(), 'one partial return against an invoice carrying both discounts');

        $longest = DB::table('products')->max(DB::raw('CHAR_LENGTH(name)'));
        $this->assertGreaterThan(45, $longest, 'a deliberately long name for the stacked card');
    }

    public function test_every_balance_comes_from_the_ledger_and_none_is_negative(): void
    {
        $this->seedAsLocal();

        $negative = DB::table('stock_movements')
            ->select('branch_id', 'variant_id', DB::raw('SUM(quantity) as q'))
            ->groupBy('branch_id', 'variant_id')
            ->havingRaw('SUM(quantity) < 0')
            ->count();

        $this->assertSame(0, $negative, 'the demo sold stock it did not have');
        $this->assertGreaterThan(200, DB::table('stock_movements')->count());
    }
}
