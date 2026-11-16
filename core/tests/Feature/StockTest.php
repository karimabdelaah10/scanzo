<?php

namespace Tests\Feature;

use App\Enums\StockMovementType;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\StockLedger;
use App\Support\BusinessContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_delivery_goes_on_the_shelf(): void
    {
        $this->tenant();
        [$branch] = $this->shopFloor();
        $owner = User::factory()->owner()->create();

        $shirt = ProductVariant::factory()->create();
        $jeans = ProductVariant::factory()->create();

        $this->actingAs($owner)->postJson('/api/stock/receive', [
            'branch_id' => $branch->id,
            'items' => [
                ['variant_id' => $shirt->id, 'quantity' => 12],
                ['variant_id' => $jeans->id, 'quantity' => 6],
            ],
            'note' => 'فاتورة مورد 1043',
        ])->assertOk()->assertJsonCount(2, 'data');

        $ledger = app(StockLedger::class);
        $this->assertSame(12, $ledger->balance($shirt->id, $branch->id));
        $this->assertSame(6, $ledger->balance($jeans->id, $branch->id));

        $movement = StockMovement::where('variant_id', $shirt->id)->firstOrFail();
        $this->assertSame(StockMovementType::Purchase, $movement->type);
        $this->assertSame('فاتورة مورد 1043', $movement->note);
        $this->assertSame($owner->id, $movement->user_id);
    }

    public function test_the_same_item_twice_in_one_delivery_is_added_up(): void
    {
        $this->tenant();
        [$branch] = $this->shopFloor();
        $owner = User::factory()->owner()->create();

        $variant = ProductVariant::factory()->create();

        $this->actingAs($owner)->postJson('/api/stock/receive', [
            'branch_id' => $branch->id,
            'items' => [
                ['variant_id' => $variant->id, 'quantity' => 4],
                ['variant_id' => $variant->id, 'quantity' => 3],
            ],
        ])->assertOk()->assertJsonCount(1, 'data');

        $this->assertSame(7, app(StockLedger::class)->balance($variant->id, $branch->id));
    }

    public function test_stock_lives_per_branch(): void
    {
        $this->tenant();
        [$main] = $this->shopFloor();
        [$second] = $this->shopFloor();
        $owner = User::factory()->owner()->create();

        $variant = ProductVariant::factory()->create();

        $this->actingAs($owner)->postJson('/api/stock/receive', [
            'branch_id' => $main->id,
            'items' => [['variant_id' => $variant->id, 'quantity' => 10]],
        ])->assertOk();

        $ledger = app(StockLedger::class);
        $this->assertSame(10, $ledger->balance($variant->id, $main->id));
        $this->assertSame(0, $ledger->balance($variant->id, $second->id));
    }

    public function test_a_count_posts_only_the_difference(): void
    {
        $this->tenant();
        [$branch] = $this->shopFloor();
        $owner = User::factory()->owner()->create();

        $short = ProductVariant::factory()->create();
        $over = ProductVariant::factory()->create();
        $right = ProductVariant::factory()->create();

        $this->giveStock($short, $branch, 9);
        $this->giveStock($over, $branch, 2);
        $this->giveStock($right, $branch, 5);

        $response = $this->actingAs($owner)->postJson('/api/stock/adjust', [
            'branch_id' => $branch->id,
            'items' => [
                // Counted 7, ledger said 9: two pieces are missing.
                ['variant_id' => $short->id, 'counted' => 7],
                // Counted 4, ledger said 2: two were never booked in.
                ['variant_id' => $over->id, 'counted' => 4],
                // Counted 5, ledger said 5.
                ['variant_id' => $right->id, 'counted' => 5],
            ],
            'note' => 'جرد نهاية الشهر',
        ])->assertOk();

        $response->assertJsonPath('meta.corrected', 2)
            ->assertJsonPath('meta.unchanged', 1)
            ->assertJsonPath('data.0.was', 9)
            ->assertJsonPath('data.0.counted', 7)
            ->assertJsonPath('data.0.difference', -2);

        $ledger = app(StockLedger::class);
        $this->assertSame(7, $ledger->balance($short->id, $branch->id));
        $this->assertSame(4, $ledger->balance($over->id, $branch->id));
        $this->assertSame(5, $ledger->balance($right->id, $branch->id));

        // A count that matched wrote nothing at all.
        $adjustments = StockMovement::where('type', StockMovementType::Adjustment)->get();
        $this->assertCount(2, $adjustments);
        $this->assertFalse($adjustments->contains('variant_id', $right->id));
    }

    public function test_a_count_of_zero_clears_the_shelf(): void
    {
        $this->tenant();
        [$branch] = $this->shopFloor();
        $owner = User::factory()->owner()->create();

        $variant = ProductVariant::factory()->create();
        $this->giveStock($variant, $branch, 3);

        $this->actingAs($owner)->postJson('/api/stock/adjust', [
            'branch_id' => $branch->id,
            'items' => [['variant_id' => $variant->id, 'counted' => 0]],
        ])
            ->assertOk()
            ->assertJsonPath('data.0.difference', -3);

        $this->assertSame(0, app(StockLedger::class)->balance($variant->id, $branch->id));
    }

    public function test_the_shelf_report_shows_what_is_running_out(): void
    {
        $this->tenant();
        [$branch] = $this->shopFloor();
        $owner = User::factory()->owner()->create();

        $product = Product::factory()->create(['name' => 'قميص كتان']);
        // The sku order and the quantity order deliberately disagree:
        //   by sku:      20001 (2), 20002 (20), 20003 (1)
        //   by quantity: 20003 (1), 20001 (2), 20002 (20)
        // so the two assertions below cannot pass by accident.
        $low = ProductVariant::factory()->create(['product_id' => $product->id, 'sku' => 'SKU-20001']);
        $plenty = ProductVariant::factory()->create(['product_id' => $product->id, 'sku' => 'SKU-20002']);
        $last = ProductVariant::factory()->create(['product_id' => $product->id, 'sku' => 'SKU-20003']);

        $this->giveStock($low, $branch, 2);
        $this->giveStock($plenty, $branch, 20);
        $this->giveStock($last, $branch, 1);

        // Without a filter the order is by sku.
        $this->actingAs($owner)->getJson("/api/stock?branch_id={$branch->id}")
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.sku', 'SKU-20001')
            ->assertJsonPath('data.0.quantity', 2)
            ->assertJsonPath('data.0.product_name', 'قميص كتان')
            ->assertJsonPath('data.1.sku', 'SKU-20002')
            ->assertJsonPath('data.2.sku', 'SKU-20003');

        // The one report a shop opens every morning.
        $this->actingAs($owner)->getJson("/api/stock?branch_id={$branch->id}&low=2")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            // With `low`, the order is by quantity, not by sku, on purpose:
            // the closest to running out comes first.
            ->assertJsonPath('data.0.sku', 'SKU-20003')   // 1 left
            ->assertJsonPath('data.1.sku', 'SKU-20001');  // 2 left
    }

    public function test_low_stock_is_found_across_the_whole_catalogue_not_just_the_first_page(): void
    {
        $this->tenant();
        [$branch] = $this->shopFloor();
        $owner = User::factory()->owner()->create();

        // 59 well-stocked variants, and one nearly gone whose sku sorts last,
        // so before the fix it landed on page 2 and the filter saw nothing.
        for ($i = 1; $i <= 59; $i++) {
            $variant = ProductVariant::factory()->create(['sku' => sprintf('SKU-5%04d', $i)]);
            $this->giveStock($variant, $branch, 10);
        }

        $last = ProductVariant::factory()->create(['sku' => 'SKU-59999']);
        $this->giveStock($last, $branch, 1);

        $this->actingAs($owner)->getJson("/api/stock?branch_id={$branch->id}&low=2")
            ->assertOk()
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.sku', 'SKU-59999')
            ->assertJsonPath('data.0.quantity', 1);
    }

    public function test_the_total_counts_the_filtered_list_not_the_whole_catalogue(): void
    {
        $this->tenant();
        [$branch] = $this->shopFloor();
        $owner = User::factory()->owner()->create();

        foreach ([20, 15, 1, 0, 2] as $i => $quantity) {
            $variant = ProductVariant::factory()->create(['sku' => 'SKU-6000'.$i]);

            if ($quantity !== 0) {
                $this->giveStock($variant, $branch, $quantity);
            }
        }

        $this->actingAs($owner)->getJson("/api/stock?branch_id={$branch->id}")
            ->assertOk()
            ->assertJsonPath('meta.total_variants', 5);

        // 1, 0 (never stocked) and 2 are at or below 2.
        $this->actingAs($owner)->getJson("/api/stock?branch_id={$branch->id}&low=2")
            ->assertOk()
            ->assertJsonPath('meta.total_variants', 3)
            ->assertJsonCount(3, 'data');
    }

    public function test_low_stock_lists_the_closest_to_running_out_first(): void
    {
        $this->tenant();
        [$branch] = $this->shopFloor();
        $owner = User::factory()->owner()->create();

        // The skus run in the opposite order to the quantities, so sorting by
        // sku would give the wrong answer.
        $two = ProductVariant::factory()->create(['sku' => 'SKU-70001']);
        $one = ProductVariant::factory()->create(['sku' => 'SKU-70002']);
        $none = ProductVariant::factory()->create(['sku' => 'SKU-70003']);

        $this->giveStock($two, $branch, 2);
        $this->giveStock($one, $branch, 1);

        $this->actingAs($owner)->getJson("/api/stock?branch_id={$branch->id}&low=5")
            ->assertOk()
            ->assertJsonPath('data.0.sku', $none->sku)
            ->assertJsonPath('data.0.quantity', 0)
            ->assertJsonPath('data.1.sku', 'SKU-70002')
            ->assertJsonPath('data.2.sku', 'SKU-70001');
    }

    public function test_overselling_while_offline_shows_up_as_a_negative_shelf(): void
    {
        // Two tills that each sold the last piece with no connection both
        // posted a valid movement. The balance going below zero is how the
        // shop finds out, instead of one sale being lost.
        $this->tenant();
        [$branch, $device, $seller] = $this->shopFloor();
        $owner = User::factory()->owner()->create();

        $variant = ProductVariant::factory()->create(['sku' => 'SKU-30001']);
        $this->giveStock($variant, $branch, 1);
        $this->sell($seller, $device, [['variant_id' => $variant->id, 'quantity' => 1]]);

        // The second till's sale arrives late.
        app(StockLedger::class)->post(
            variant: $variant,
            branchId: $branch->id,
            quantity: -1,
            type: StockMovementType::Sale,
            note: 'late sync from another till',
        );

        $this->assertSame(-1, app(StockLedger::class)->balance($variant->id, $branch->id));

        $this->actingAs($owner)->getJson("/api/stock?branch_id={$branch->id}&negative=1")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.sku', 'SKU-30001')
            ->assertJsonPath('data.0.quantity', -1);
    }

    public function test_the_history_says_who_moved_what_and_why(): void
    {
        $this->tenant();
        [$branch, $device, $seller] = $this->shopFloor();
        $owner = User::factory()->owner()->create();

        $variant = ProductVariant::factory()->create();
        $this->giveStock($variant, $branch, 10);
        $invoice = $this->sell($seller, $device, [['variant_id' => $variant->id, 'quantity' => 2]]);

        $response = $this->actingAs($owner)
            ->getJson("/api/stock/movements?variant_id={$variant->id}")
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $sale = collect($response->json('data'))->firstWhere('type', 'sale');

        $this->assertSame(-2, $sale['quantity']);
        $this->assertSame('Invoice', $sale['reference_type']);
        $this->assertSame($invoice->id, $sale['reference_id']);
        $this->assertSame($seller->id, $sale['user_id']);
        $this->assertSame($device->id, $sale['device_id']);
    }

    public function test_a_seller_cannot_receive_or_adjust_stock(): void
    {
        $this->tenant();
        [$branch, , $seller] = $this->shopFloor();

        $variant = ProductVariant::factory()->create();

        $this->actingAs($seller)->postJson('/api/stock/receive', [
            'branch_id' => $branch->id,
            'items' => [['variant_id' => $variant->id, 'quantity' => 5]],
        ])
            ->assertForbidden()
            ->assertJsonPath('code', 'stock_not_allowed');

        $this->actingAs($seller)->postJson('/api/stock/adjust', [
            'branch_id' => $branch->id,
            'items' => [['variant_id' => $variant->id, 'counted' => 5]],
        ])
            ->assertForbidden()
            ->assertJsonPath('code', 'stock_not_allowed');

        $this->assertSame(0, StockMovement::count());
    }

    public function test_a_branch_manager_cannot_touch_another_branch(): void
    {
        $this->tenant();
        [$mine] = $this->shopFloor();
        [$theirs] = $this->shopFloor();
        $manager = $this->manager($mine);

        $variant = ProductVariant::factory()->create();

        $this->actingAs($manager)->postJson('/api/stock/receive', [
            'branch_id' => $theirs->id,
            'items' => [['variant_id' => $variant->id, 'quantity' => 5]],
        ])
            ->assertForbidden()
            ->assertJsonPath('code', 'branch_not_allowed');

        $this->actingAs($manager)->getJson("/api/stock?branch_id={$theirs->id}")
            ->assertForbidden();

        $this->assertSame(0, StockMovement::count());
    }

    public function test_a_branch_from_another_business_fails_validation(): void
    {
        $this->tenant();
        [$otherBranch] = $this->shopFloor();

        $this->tenant();
        $owner = User::factory()->owner()->create();
        $variant = ProductVariant::factory()->create();

        $this->actingAs($owner)->postJson('/api/stock/receive', [
            'branch_id' => $otherBranch->id,
            'items' => [['variant_id' => $variant->id, 'quantity' => 5]],
        ])->assertStatus(422);

        $this->assertSame(0, StockMovement::count());
    }

    protected function tearDown(): void
    {
        app(BusinessContext::class)->forgetBusiness();

        parent::tearDown();
    }
}
