<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\ProductVariant;
use App\Models\SaleReturn;
use App\Services\StockLedger;
use App\Support\BusinessContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExchangeTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_exchange_is_a_return_and_a_new_invoice_linked_together(): void
    {
        $this->tenant();
        [$branch, $device, $seller] = $this->shopFloor();
        $manager = $this->manager($branch);

        $small = ProductVariant::factory()->create(['price' => 450, 'size' => 'S']);
        $large = ProductVariant::factory()->create(['price' => 650, 'size' => 'L']);
        $this->giveStock($small, $branch, 4);
        $this->giveStock($large, $branch, 4);

        $invoice = $this->sell($seller, $device, [['variant_id' => $small->id, 'quantity' => 1]]);
        $line = $invoice->items()->firstOrFail();

        $response = $this->actingAs($manager)->postJson('/api/returns', [
            'device_id' => $device->id,
            'invoice_id' => $invoice->id,
            'items' => [['invoice_item_id' => $line->id, 'quantity' => 1]],
            'exchange_items' => [['variant_id' => $large->id, 'quantity' => 1]],
            'reason' => 'استبدال مقاس',
        ])->assertCreated();

        $response
            ->assertJsonPath('data.refund_method', 'exchange')
            ->assertJsonPath('data.refund_amount', '450.00')
            // The new invoice carries the full value of what was sold; the
            // return carries the credit. The difference is the customer's.
            ->assertJsonPath('meta.exchange_invoice.total', '650.00')
            ->assertJsonPath('meta.difference', '200.00')
            ->assertJsonPath('meta.customer_pays', true);

        $saleReturn = SaleReturn::firstOrFail();
        $newInvoice = Invoice::findOrFail($response->json('meta.exchange_invoice.id'));

        $this->assertSame($newInvoice->id, $saleReturn->exchange_invoice_id);
        $this->assertSame($invoice->id, $saleReturn->invoice_id);
        $this->assertTrue($saleReturn->isExchange());

        $ledger = app(StockLedger::class);
        // The small one came back, the large one went out.
        $this->assertSame(4, $ledger->balance($small->id, $branch->id));
        $this->assertSame(3, $ledger->balance($large->id, $branch->id));

        $this->assertSame(1, $line->fresh()->returned_quantity);
    }

    public function test_an_unknown_payment_method_on_an_exchange_is_refused_not_turned_into_cash(): void
    {
        $this->tenant();
        [$branch, $device, $seller] = $this->shopFloor();
        $manager = $this->manager($branch);

        $small = ProductVariant::factory()->create(['price' => 450]);
        $large = ProductVariant::factory()->create(['price' => 650]);
        $this->giveStock($small, $branch, 4);
        $this->giveStock($large, $branch, 4);

        $invoice = $this->sell($seller, $device, [['variant_id' => $small->id, 'quantity' => 1]]);
        $line = $invoice->items()->firstOrFail();

        // "mixed" is a real enum case but not accepted yet, so it is refused too.
        foreach (['haha', 'mixed'] as $method) {
            $this->actingAs($manager)->postJson('/api/returns', [
                'device_id' => $device->id,
                'invoice_id' => $invoice->id,
                'items' => [['invoice_item_id' => $line->id, 'quantity' => 1]],
                'exchange_items' => [['variant_id' => $large->id, 'quantity' => 1]],
                'payment_method' => $method,
            ])->assertStatus(422)->assertJsonValidationErrors('payment_method');
        }

        $this->assertSame(0, SaleReturn::count());
        $this->assertSame(1, Invoice::count());
    }

    public function test_the_shop_owes_the_difference_when_the_new_piece_is_cheaper(): void
    {
        $this->tenant();
        [$branch, $device, $seller] = $this->shopFloor();
        $manager = $this->manager($branch);

        $expensive = ProductVariant::factory()->create(['price' => 650]);
        $cheap = ProductVariant::factory()->create(['price' => 450]);
        $this->giveStock($expensive, $branch, 3);
        $this->giveStock($cheap, $branch, 3);

        $invoice = $this->sell($seller, $device, [['variant_id' => $expensive->id, 'quantity' => 1]]);
        $line = $invoice->items()->firstOrFail();

        $this->actingAs($manager)->postJson('/api/returns', [
            'device_id' => $device->id,
            'invoice_id' => $invoice->id,
            'items' => [['invoice_item_id' => $line->id, 'quantity' => 1]],
            'exchange_items' => [['variant_id' => $cheap->id, 'quantity' => 1]],
        ])
            ->assertCreated()
            ->assertJsonPath('meta.difference', '200.00')
            ->assertJsonPath('meta.customer_pays', false);
    }

    public function test_an_even_swap_leaves_nothing_to_pay(): void
    {
        $this->tenant();
        [$branch, $device, $seller] = $this->shopFloor();
        $manager = $this->manager($branch);

        $blue = ProductVariant::factory()->create(['price' => 450, 'color' => 'أزرق']);
        $white = ProductVariant::factory()->create(['price' => 450, 'color' => 'أبيض']);
        $this->giveStock($blue, $branch, 2);
        $this->giveStock($white, $branch, 2);

        $invoice = $this->sell($seller, $device, [['variant_id' => $blue->id, 'quantity' => 1]]);
        $line = $invoice->items()->firstOrFail();

        $this->actingAs($manager)->postJson('/api/returns', [
            'device_id' => $device->id,
            'invoice_id' => $invoice->id,
            'items' => [['invoice_item_id' => $line->id, 'quantity' => 1]],
            'exchange_items' => [['variant_id' => $white->id, 'quantity' => 1]],
        ])
            ->assertCreated()
            ->assertJsonPath('meta.difference', '0.00')
            ->assertJsonPath('meta.customer_pays', true);
    }

    public function test_an_exchange_for_something_out_of_stock_refunds_nothing(): void
    {
        // The whole thing is one transaction: if the replacement cannot be
        // sold, the return must not have happened either.
        $this->tenant();
        [$branch, $device, $seller] = $this->shopFloor();
        $manager = $this->manager($branch);

        $sold = ProductVariant::factory()->create(['price' => 450]);
        $wanted = ProductVariant::factory()->create(['price' => 450]);
        $this->giveStock($sold, $branch, 3);
        // $wanted has never been stocked.

        $invoice = $this->sell($seller, $device, [['variant_id' => $sold->id, 'quantity' => 1]]);
        $line = $invoice->items()->firstOrFail();

        $this->actingAs($manager)->postJson('/api/returns', [
            'device_id' => $device->id,
            'invoice_id' => $invoice->id,
            'items' => [['invoice_item_id' => $line->id, 'quantity' => 1]],
            'exchange_items' => [['variant_id' => $wanted->id, 'quantity' => 1]],
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'out_of_stock');

        $this->assertSame(0, SaleReturn::count());
        // Only the original sale exists.
        $this->assertSame(1, Invoice::count());
        $this->assertSame(0, $line->fresh()->returned_quantity);
        $this->assertSame(2, app(StockLedger::class)->balance($sold->id, $branch->id));
    }

    public function test_a_seller_cannot_run_an_exchange_when_the_shop_says_managers_only(): void
    {
        $this->tenant();
        [$branch, $device, $seller] = $this->shopFloor();

        $a = ProductVariant::factory()->create(['price' => 100]);
        $b = ProductVariant::factory()->create(['price' => 100]);
        $this->giveStock($a, $branch, 3);
        $this->giveStock($b, $branch, 3);

        $invoice = $this->sell($seller, $device, [['variant_id' => $a->id, 'quantity' => 1]]);
        $line = $invoice->items()->firstOrFail();

        $this->actingAs($seller)->postJson('/api/returns', [
            'device_id' => $device->id,
            'invoice_id' => $invoice->id,
            'items' => [['invoice_item_id' => $line->id, 'quantity' => 1]],
            'exchange_items' => [['variant_id' => $b->id, 'quantity' => 1]],
        ])
            ->assertForbidden()
            ->assertJsonPath('code', 'returns_not_allowed');

        $this->assertSame(0, SaleReturn::count());
        $this->assertSame(1, Invoice::count());
    }

    protected function tearDown(): void
    {
        app(BusinessContext::class)->forgetBusiness();

        parent::tearDown();
    }
}
