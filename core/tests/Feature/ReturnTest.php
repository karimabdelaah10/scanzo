<?php

namespace Tests\Feature;

use App\Enums\StockMovementType;
use App\Models\Invoice;
use App\Models\ProductVariant;
use App\Models\SaleReturn;
use App\Models\StockMovement;
use App\Services\StockLedger;
use App\Support\BusinessContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReturnTest extends TestCase
{
    use RefreshDatabase;

    public function test_scanning_a_receipt_shows_what_can_still_come_back(): void
    {
        $this->tenant();
        [$branch, $device, $seller] = $this->shopFloor();
        $manager = $this->manager($branch);

        $variant = ProductVariant::factory()->create(['price' => 450]);
        $this->giveStock($variant, $branch, 5);
        $invoice = $this->sell($seller, $device, [['variant_id' => $variant->id, 'quantity' => 2]]);

        $this->actingAs($manager)
            ->getJson('/api/returns/lookup?number='.$invoice->number)
            ->assertOk()
            ->assertJsonPath('data.number', $invoice->number)
            ->assertJsonPath('data.items.0.quantity', 2)
            ->assertJsonPath('data.items.0.returned_quantity', 0)
            ->assertJsonPath('data.items.0.returnable_quantity', 2)
            ->assertJsonPath('meta.within_return_window', true)
            ->assertJsonPath('meta.return_window_days', 14)
            ->assertJsonPath('meta.may_override_window', true);
    }

    public function test_a_return_refunds_the_sold_price_and_puts_the_piece_back(): void
    {
        $this->tenant();
        [$branch, $device, $seller] = $this->shopFloor();
        $manager = $this->manager($branch);

        $variant = ProductVariant::factory()->create(['price' => 450]);
        $this->giveStock($variant, $branch, 5);
        $invoice = $this->sell($seller, $device, [['variant_id' => $variant->id, 'quantity' => 2]]);
        $line = $invoice->items()->firstOrFail();

        // The shelf is at 3 after selling 2 of 5.
        $this->assertSame(3, app(StockLedger::class)->balance($variant->id, $branch->id));

        // The price changes after the sale; the refund must not follow it.
        $variant->forceFill(['price' => 999])->save();

        $this->actingAs($manager)->postJson('/api/returns', [
            'device_id' => $device->id,
            'invoice_id' => $invoice->id,
            'items' => [['invoice_item_id' => $line->id, 'quantity' => 1]],
            'refund_method' => 'cash',
            'reason' => 'المقاس مش مناسب',
        ])
            ->assertCreated()
            ->assertJsonPath('data.refund_amount', '450.00')
            ->assertJsonPath('data.refund_method', 'cash')
            ->assertJsonCount(1, 'data.items');

        $this->assertSame(4, app(StockLedger::class)->balance($variant->id, $branch->id));
        $this->assertSame(1, $line->fresh()->returned_quantity);

        $movement = StockMovement::where('type', StockMovementType::Return)->firstOrFail();
        $this->assertSame(1, $movement->quantity);
        $this->assertSame(SaleReturn::firstOrFail()->id, $movement->reference_id);
    }

    public function test_the_same_piece_cannot_come_back_twice(): void
    {
        $this->tenant();
        [$branch, $device, $seller] = $this->shopFloor();
        $manager = $this->manager($branch);

        $variant = ProductVariant::factory()->create(['price' => 100]);
        $this->giveStock($variant, $branch, 5);
        $invoice = $this->sell($seller, $device, [['variant_id' => $variant->id, 'quantity' => 2]]);
        $line = $invoice->items()->firstOrFail();

        $payload = fn (int $quantity) => [
            'device_id' => $device->id,
            'invoice_id' => $invoice->id,
            'items' => [['invoice_item_id' => $line->id, 'quantity' => $quantity]],
        ];

        // Both pieces come back, one at a time.
        $this->actingAs($manager)->postJson('/api/returns', $payload(1))->assertCreated();
        $this->actingAs($manager)->postJson('/api/returns', $payload(1))->assertCreated();

        // A third has nothing left to come back from.
        $this->actingAs($manager)->postJson('/api/returns', $payload(1))
            ->assertStatus(422)
            ->assertJsonPath('code', 'already_returned')
            ->assertJsonPath('context.remaining', 0);

        $this->assertSame(2, $line->fresh()->returned_quantity);
        $this->assertSame(2, SaleReturn::count());
        $this->assertSame(5, app(StockLedger::class)->balance($variant->id, $branch->id));
    }

    public function test_returning_more_than_was_bought_is_refused(): void
    {
        $this->tenant();
        [$branch, $device, $seller] = $this->shopFloor();
        $manager = $this->manager($branch);

        $variant = ProductVariant::factory()->create(['price' => 100]);
        $this->giveStock($variant, $branch, 5);
        $invoice = $this->sell($seller, $device, [['variant_id' => $variant->id, 'quantity' => 1]]);
        $line = $invoice->items()->firstOrFail();

        $this->actingAs($manager)->postJson('/api/returns', [
            'device_id' => $device->id,
            'invoice_id' => $invoice->id,
            'items' => [['invoice_item_id' => $line->id, 'quantity' => 2]],
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'already_returned')
            ->assertJsonPath('context.remaining', 1);

        $this->assertSame(0, SaleReturn::count());
        $this->assertSame(0, $line->fresh()->returned_quantity);
    }

    public function test_a_damaged_piece_is_refunded_but_not_put_back_on_the_shelf(): void
    {
        $this->tenant();
        [$branch, $device, $seller] = $this->shopFloor();
        $manager = $this->manager($branch);

        $variant = ProductVariant::factory()->create(['price' => 300]);
        $this->giveStock($variant, $branch, 4);
        $invoice = $this->sell($seller, $device, [['variant_id' => $variant->id, 'quantity' => 1]]);
        $line = $invoice->items()->firstOrFail();

        $this->actingAs($manager)->postJson('/api/returns', [
            'device_id' => $device->id,
            'invoice_id' => $invoice->id,
            'items' => [['invoice_item_id' => $line->id, 'quantity' => 1, 'condition' => 'damaged']],
        ])
            ->assertCreated()
            ->assertJsonPath('data.refund_amount', '300.00')
            ->assertJsonPath('data.items.0.restock', false)
            ->assertJsonPath('data.items.0.condition', 'damaged');

        // Still 3: the money went back, the piece did not.
        $this->assertSame(3, app(StockLedger::class)->balance($variant->id, $branch->id));
        $this->assertSame(0, StockMovement::where('type', StockMovementType::Return)->count());
        $this->assertSame(1, $line->fresh()->returned_quantity);
    }

    public function test_a_return_past_the_window_needs_a_branch_manager(): void
    {
        $business = $this->tenant();
        [$branch, $device, $seller] = $this->shopFloor();
        $manager = $this->manager($branch);

        $variant = ProductVariant::factory()->create(['price' => 200]);
        $this->giveStock($variant, $branch, 5);
        $invoice = $this->sell($seller, $device, [['variant_id' => $variant->id, 'quantity' => 1]]);
        $line = $invoice->items()->firstOrFail();

        // Older than the shop's 14-day window.
        $invoice->forceFill(['issued_at' => now()->subDays(30)])->save();

        $this->assertSame(14, $business->returnWindowDays());

        $base = [
            'device_id' => $device->id,
            'invoice_id' => $invoice->id,
            'items' => [['invoice_item_id' => $line->id, 'quantity' => 1]],
        ];

        // Refused without an override.
        $this->actingAs($manager)->postJson('/api/returns', $base)
            ->assertStatus(422)
            ->assertJsonPath('code', 'outside_return_window')
            ->assertJsonPath('context.window_days', 14);

        // The manager accepts it anyway.
        $this->actingAs($manager)
            ->postJson('/api/returns', $base + ['override_window' => true])
            ->assertCreated()
            ->assertJsonPath('data.refund_amount', '200.00');

        $this->assertSame(1, SaleReturn::count());
    }

    public function test_a_seller_cannot_wave_through_an_expired_invoice(): void
    {
        $this->tenant(['settings' => [
            'returns' => ['window_days' => 14, 'sellers_allowed' => true],
        ]]);
        [$branch, $device, $seller] = $this->shopFloor();

        $variant = ProductVariant::factory()->create(['price' => 200]);
        $this->giveStock($variant, $branch, 5);
        $invoice = $this->sell($seller, $device, [['variant_id' => $variant->id, 'quantity' => 1]]);
        $line = $invoice->items()->firstOrFail();
        $invoice->forceFill(['issued_at' => now()->subDays(30)])->save();

        $this->actingAs($seller)->postJson('/api/returns', [
            'device_id' => $device->id,
            'invoice_id' => $invoice->id,
            'items' => [['invoice_item_id' => $line->id, 'quantity' => 1]],
            'override_window' => true,
        ])
            ->assertForbidden()
            ->assertJsonPath('code', 'window_override_not_allowed');

        $this->assertSame(0, SaleReturn::count());
    }

    public function test_whether_a_seller_may_issue_returns_is_the_shops_own_setting(): void
    {
        // Default: managers only.
        $business = $this->tenant();
        [$branch, $device, $seller] = $this->shopFloor();

        $variant = ProductVariant::factory()->create(['price' => 120]);
        $this->giveStock($variant, $branch, 5);
        $invoice = $this->sell($seller, $device, [['variant_id' => $variant->id, 'quantity' => 1]]);
        $line = $invoice->items()->firstOrFail();

        $payload = [
            'device_id' => $device->id,
            'invoice_id' => $invoice->id,
            'items' => [['invoice_item_id' => $line->id, 'quantity' => 1]],
        ];

        $this->actingAs($seller)->postJson('/api/returns', $payload)
            ->assertForbidden()
            ->assertJsonPath('code', 'returns_not_allowed');

        // The shop turns it on. Read from the business directly: lazy loading
        // is off in tests, so $seller->business would throw here.
        $business->forceFill(['settings' => [
            'returns' => ['window_days' => 14, 'sellers_allowed' => true],
        ]])->save();

        $this->actingAs($seller->fresh())->postJson('/api/returns', $payload)
            ->assertCreated()
            ->assertJsonPath('data.refund_amount', '120.00');
    }

    public function test_a_receipt_from_another_business_resolves_to_nothing(): void
    {
        $this->tenant();
        [$otherBranch, $otherDevice, $otherSeller] = $this->shopFloor();
        $otherVariant = ProductVariant::factory()->create(['price' => 100]);
        $this->giveStock($otherVariant, $otherBranch, 3);
        $otherInvoice = $this->sell($otherSeller, $otherDevice, [['variant_id' => $otherVariant->id, 'quantity' => 1]]);

        $this->tenant();
        [$branch] = $this->shopFloor();
        $manager = $this->manager($branch);

        $this->actingAs($manager)
            ->getJson('/api/returns/lookup?number='.$otherInvoice->number)
            ->assertNotFound()
            ->assertJsonPath('code', 'invoice_not_found');
    }

    public function test_a_resent_return_does_not_refund_twice(): void
    {
        $this->tenant();
        [$branch, $device, $seller] = $this->shopFloor();
        $manager = $this->manager($branch);

        $variant = ProductVariant::factory()->create(['price' => 250]);
        $this->giveStock($variant, $branch, 5);
        $invoice = $this->sell($seller, $device, [['variant_id' => $variant->id, 'quantity' => 2]]);
        $line = $invoice->items()->firstOrFail();

        $payload = [
            'id' => (string) \Illuminate\Support\Str::orderedUuid(),
            'device_id' => $device->id,
            'invoice_id' => $invoice->id,
            'items' => [['invoice_item_id' => $line->id, 'quantity' => 1]],
        ];

        $first = $this->actingAs($manager)->postJson('/api/returns', $payload)->assertCreated();
        $second = $this->actingAs($manager)->postJson('/api/returns', $payload)->assertCreated();

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, SaleReturn::count());
        $this->assertSame(1, $line->fresh()->returned_quantity);
        $this->assertSame(4, app(StockLedger::class)->balance($variant->id, $branch->id));
    }

    public function test_return_numbers_are_separate_from_invoice_numbers(): void
    {
        $this->tenant();
        [$branch, $device, $seller] = $this->shopFloor();
        $manager = $this->manager($branch);

        $variant = ProductVariant::factory()->create(['price' => 100]);
        $this->giveStock($variant, $branch, 10);
        $invoice = $this->sell($seller, $device, [['variant_id' => $variant->id, 'quantity' => 2]]);
        $line = $invoice->items()->firstOrFail();

        $response = $this->actingAs($manager)->postJson('/api/returns', [
            'device_id' => $device->id,
            'invoice_id' => $invoice->id,
            'items' => [['invoice_item_id' => $line->id, 'quantity' => 1]],
        ])->assertCreated();

        $this->assertSame($device->code.'-R-000001', $response->json('data.number'));
        // The invoice counter is untouched by it.
        $this->assertSame($device->code.'-000001', $invoice->number);
    }

    protected function tearDown(): void
    {
        app(BusinessContext::class)->forgetBusiness();

        parent::tearDown();
    }
}
