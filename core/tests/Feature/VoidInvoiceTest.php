<?php

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Enums\StockMovementType;
use App\Models\Invoice;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\StockLedger;
use App\Support\BusinessContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VoidInvoiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_manager_cancels_an_invoice_and_the_stock_comes_back_as_cancellation_movements(): void
    {
        $this->tenant();
        [$branch, $device, $seller] = $this->shopFloor();
        $manager = $this->manager($branch);

        $shirt = ProductVariant::factory()->create(['price' => 450]);
        $jeans = ProductVariant::factory()->create(['price' => 650]);
        $this->giveStock($shirt, $branch, 6);
        $this->giveStock($jeans, $branch, 4);

        $invoice = $this->sell($seller, $device, [
            ['variant_id' => $shirt->id, 'quantity' => 2],
            ['variant_id' => $jeans->id, 'quantity' => 1],
        ]);

        $this->actingAs($manager)->postJson("/api/invoices/{$invoice->id}/void", ['reason' => 'غلطة في الصنف'])
            ->assertOk()
            ->assertJsonPath('data.status', 'voided')
            ->assertJsonPath('data.void_reason', 'غلطة في الصنف')
            ->assertJsonPath('data.voided_by_name', $manager->name)
            ->assertJsonPath('data.can_void', false);

        $invoice->refresh();

        $this->assertSame(InvoiceStatus::Voided, $invoice->status);
        $this->assertSame($manager->id, $invoice->voided_by);
        $this->assertNotNull($invoice->voided_at);

        // Kept: same number, same lines, same totals.
        $this->assertSame(2, $invoice->items()->count());

        // Back to the opening balance, through new movements and not by editing the old ones.
        $ledger = app(StockLedger::class);
        $this->assertSame(6, $ledger->balance($shirt->id, $branch->id));
        $this->assertSame(4, $ledger->balance($jeans->id, $branch->id));
        $this->assertSame(2, StockMovement::where('type', StockMovementType::Sale)->count());
        $this->assertSame(2, StockMovement::where('type', StockMovementType::Cancellation)->count());
        $this->assertSame(0, StockMovement::where('type', StockMovementType::Return)->count());
    }

    public function test_a_seller_cannot_cancel_even_their_own_invoice(): void
    {
        $this->tenant();
        [$branch, $device, $seller] = $this->shopFloor();

        $shirt = ProductVariant::factory()->create(['price' => 450]);
        $this->giveStock($shirt, $branch, 3);
        $invoice = $this->sell($seller, $device, [['variant_id' => $shirt->id, 'quantity' => 1]]);

        $this->actingAs($seller)->postJson("/api/invoices/{$invoice->id}/void", ['reason' => 'x'])
            ->assertForbidden()
            ->assertJsonPath('code', 'void_not_allowed');

        $this->assertSame(InvoiceStatus::Completed, $invoice->fresh()->status);
        $this->assertSame(2, app(StockLedger::class)->balance($shirt->id, $branch->id));
    }

    public function test_a_manager_cannot_cancel_in_another_branch_but_the_owner_can(): void
    {
        $this->tenant();
        [$branch, $device, $seller] = $this->shopFloor();
        [$otherBranch] = $this->shopFloor();

        $shirt = ProductVariant::factory()->create(['price' => 450]);
        $this->giveStock($shirt, $branch, 3);
        $invoice = $this->sell($seller, $device, [['variant_id' => $shirt->id, 'quantity' => 1]]);

        $this->actingAs($this->manager($otherBranch))
            ->postJson("/api/invoices/{$invoice->id}/void", ['reason' => 'x'])
            ->assertForbidden()
            ->assertJsonPath('code', 'branch_not_allowed');

        $this->actingAs(User::factory()->owner()->create())
            ->postJson("/api/invoices/{$invoice->id}/void", ['reason' => 'x'])
            ->assertOk();
    }

    public function test_a_reason_is_required(): void
    {
        $this->tenant();
        [$branch, $device, $seller] = $this->shopFloor();
        $manager = $this->manager($branch);

        $shirt = ProductVariant::factory()->create(['price' => 450]);
        $this->giveStock($shirt, $branch, 3);
        $invoice = $this->sell($seller, $device, [['variant_id' => $shirt->id, 'quantity' => 1]]);

        $this->actingAs($manager)->postJson("/api/invoices/{$invoice->id}/void", [])
            ->assertStatus(422)->assertJsonValidationErrors('reason');

        // Spaces are not a reason.
        $this->actingAs($manager)->postJson("/api/invoices/{$invoice->id}/void", ['reason' => '   '])
            ->assertStatus(422);

        $this->assertSame(InvoiceStatus::Completed, $invoice->fresh()->status);
    }

    public function test_an_invoice_with_a_return_is_refused(): void
    {
        $this->tenant();
        [$branch, $device, $seller] = $this->shopFloor();
        $manager = $this->manager($branch);

        $shirt = ProductVariant::factory()->create(['price' => 450]);
        $this->giveStock($shirt, $branch, 5);
        $invoice = $this->sell($seller, $device, [['variant_id' => $shirt->id, 'quantity' => 2]]);

        $this->actingAs($manager)->postJson('/api/returns', [
            'device_id' => $device->id,
            'invoice_id' => $invoice->id,
            'items' => [['invoice_item_id' => $invoice->items()->firstOrFail()->id, 'quantity' => 1]],
        ])->assertCreated();

        $this->actingAs($manager)->postJson("/api/invoices/{$invoice->id}/void", ['reason' => 'x'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'invoice_has_returns');

        $this->assertSame(InvoiceStatus::Completed, $invoice->fresh()->status);
        // 5 - 2 sold + 1 returned: nothing was put back twice.
        $this->assertSame(4, app(StockLedger::class)->balance($shirt->id, $branch->id));
    }

    public function test_the_new_invoice_of_an_exchange_is_refused(): void
    {
        $this->tenant();
        [$branch, $device, $seller] = $this->shopFloor();
        $manager = $this->manager($branch);

        $small = ProductVariant::factory()->create(['price' => 450]);
        $large = ProductVariant::factory()->create(['price' => 650]);
        $this->giveStock($small, $branch, 4);
        $this->giveStock($large, $branch, 4);
        $invoice = $this->sell($seller, $device, [['variant_id' => $small->id, 'quantity' => 1]]);

        $exchangeId = $this->actingAs($manager)->postJson('/api/returns', [
            'device_id' => $device->id,
            'invoice_id' => $invoice->id,
            'items' => [['invoice_item_id' => $invoice->items()->firstOrFail()->id, 'quantity' => 1]],
            'exchange_items' => [['variant_id' => $large->id, 'quantity' => 1]],
        ])->assertCreated()->json('meta.exchange_invoice.id');

        $this->actingAs($manager)->postJson("/api/invoices/{$exchangeId}/void", ['reason' => 'x'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'invoice_is_an_exchange');
    }

    public function test_an_invoice_cannot_be_cancelled_twice(): void
    {
        $this->tenant();
        [$branch, $device, $seller] = $this->shopFloor();
        $manager = $this->manager($branch);

        $shirt = ProductVariant::factory()->create(['price' => 450]);
        $this->giveStock($shirt, $branch, 3);
        $invoice = $this->sell($seller, $device, [['variant_id' => $shirt->id, 'quantity' => 1]]);

        $this->actingAs($manager)->postJson("/api/invoices/{$invoice->id}/void", ['reason' => 'x'])->assertOk();
        $this->actingAs($manager)->postJson("/api/invoices/{$invoice->id}/void", ['reason' => 'x'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'invoice_already_voided');

        // One cancellation, not two: the shelf is back at 3, not 4.
        $this->assertSame(3, app(StockLedger::class)->balance($shirt->id, $branch->id));
    }

    public function test_the_window_comes_from_the_shops_settings(): void
    {
        $business = $this->tenant();
        [$branch, $device, $seller] = $this->shopFloor();
        $manager = $this->manager($branch);

        $shirt = ProductVariant::factory()->create(['price' => 450]);
        $this->giveStock($shirt, $branch, 9);

        $old = $this->sell($seller, $device, [['variant_id' => $shirt->id, 'quantity' => 1]]);
        $old->forceFill(['issued_at' => now()->subHours(30)])->save();

        // Default is 24 hours: 30 hours ago is too late.
        $this->actingAs($manager)->postJson("/api/invoices/{$old->id}/void", ['reason' => 'x'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'outside_void_window')
            ->assertJsonPath('context.window_hours', 24);

        $this->actingAs($manager)->getJson("/api/invoices/{$old->id}")
            ->assertJsonPath('data.can_void', false);

        // The owner widens it; the same invoice can now be cancelled.
        $this->actingAs(User::factory()->owner()->create())
            ->patchJson('/api/settings', ['voids' => ['window_hours' => 48]])
            ->assertOk()
            ->assertJsonPath('data.voids.window_hours', 48);

        $this->assertSame(48, $business->fresh()->voidWindowHours());

        // A real request loads the user afresh; this object would keep the old shop.
        $manager->unsetRelation('business');

        $this->actingAs($manager)->getJson("/api/invoices/{$old->id}")
            ->assertJsonPath('data.can_void', true);
        $this->actingAs($manager)->postJson("/api/invoices/{$old->id}/void", ['reason' => 'x'])->assertOk();

        // And narrowing it shuts a fresh one out.
        $fresh = $this->sell($seller, $device, [['variant_id' => $shirt->id, 'quantity' => 1]]);
        $fresh->forceFill(['issued_at' => now()->subHours(3)])->save();
        $this->actingAs(User::factory()->owner()->create())
            ->patchJson('/api/settings', ['voids' => ['window_hours' => 1]])->assertOk();
        $manager->unsetRelation('business');

        $this->actingAs($manager)->postJson("/api/invoices/{$fresh->id}/void", ['reason' => 'x'])
            ->assertStatus(422)->assertJsonPath('code', 'outside_void_window');
    }

    public function test_a_cancelled_invoice_is_in_no_total_and_refuses_returns(): void
    {
        $this->tenant();
        [$branch, $device, $seller] = $this->shopFloor();
        $manager = $this->manager($branch);

        $shirt = ProductVariant::factory()->create(['price' => 450]);
        $this->giveStock($shirt, $branch, 3);
        $invoice = $this->sell($seller, $device, [['variant_id' => $shirt->id, 'quantity' => 1]]);

        $this->actingAs($manager)->postJson("/api/invoices/{$invoice->id}/void", ['reason' => 'x'])->assertOk();

        $this->actingAs($manager)->getJson('/api/invoices/summary?branch_id='.$branch->id)
            ->assertOk()
            ->assertJsonPath('data.invoices', 0);

        $this->actingAs($manager)->postJson('/api/returns', [
            'device_id' => $device->id,
            'invoice_id' => $invoice->id,
            'items' => [['invoice_item_id' => $invoice->items()->firstOrFail()->id, 'quantity' => 1]],
        ])->assertStatus(422)->assertJsonPath('code', 'invoice_voided');
    }

    public function test_another_shops_invoice_is_not_reachable(): void
    {
        $this->tenant();
        [$branch, $device, $seller] = $this->shopFloor();
        $shirt = ProductVariant::factory()->create(['price' => 450]);
        $this->giveStock($shirt, $branch, 3);
        $invoice = $this->sell($seller, $device, [['variant_id' => $shirt->id, 'quantity' => 1]]);

        $this->tenant();
        $otherOwner = User::factory()->owner()->create();

        $this->actingAs($otherOwner)->postJson("/api/invoices/{$invoice->id}/void", ['reason' => 'x'])
            ->assertNotFound();

        app(BusinessContext::class)->forgetBusiness();
        $this->assertSame(InvoiceStatus::Completed, Invoice::withoutGlobalScopes()->find($invoice->id)->status);
    }

    protected function tearDown(): void
    {
        app(BusinessContext::class)->forgetBusiness();

        parent::tearDown();
    }
}
