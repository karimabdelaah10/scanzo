<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\ProductVariant;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The invoice-level discount, spread over the lines at sale time.
 *
 * The rule that matters: a return refunds what the customer PAID for the line,
 * which is its line_total minus its stored share of the invoice discount. A
 * 450 shirt on an invoice with 100 off was not paid at 450.
 */
class InvoiceDiscountTest extends TestCase
{
    use RefreshDatabase;

    /** A 450 shirt and a 150 hat on one invoice with 100 off: shares 75 and 25. */
    private function sellShirtAndHatWithInvoiceDiscount(float $discount = 100): array
    {
        $business = $this->tenant();
        [$branch, $device, $seller] = $this->shopFloor();
        $manager = $this->manager($branch);

        $shirt = ProductVariant::factory()->create(['price' => 450]);
        $hat = ProductVariant::factory()->create(['price' => 150]);
        $this->giveStock($shirt, $branch, 10);
        $this->giveStock($hat, $branch, 10);

        $this->actingAs($manager)->postJson('/api/invoices', [
            'device_id' => $device->id,
            'items' => [
                ['variant_id' => $shirt->id, 'quantity' => 1],
                ['variant_id' => $hat->id, 'quantity' => 1],
            ],
            'discount_amount' => $discount,
        ])->assertCreated();

        $invoice = Invoice::firstOrFail();
        $shirtLine = $invoice->items()->where('sku', $shirt->sku)->firstOrFail();
        $hatLine = $invoice->items()->where('sku', $hat->sku)->firstOrFail();

        return [$business, $branch, $device, $seller, $manager, $invoice, $shirtLine, $hatLine, $shirt, $hat];
    }

    public function test_money_allocate_adds_up_exactly_and_keeps_keys(): void
    {
        // 100.00 over three equal lines: 33.33 + 33.33 + 33.34, not 99.99.
        $shares = Money::allocate(10000, ['a' => 10000, 'b' => 10000, 'c' => 10000]);

        $this->assertSame(['a', 'b', 'c'], array_keys($shares));
        $this->assertSame(10000, array_sum($shares));

        // An awkward split, in several shapes: always exact.
        foreach ([[1, 1, 1], [7, 13, 29], [1, 99999], [333, 333, 334, 1]] as $weights) {
            foreach ([1, 2, 99, 100, 101, 12345] as $total) {
                $this->assertSame($total, array_sum(Money::allocate($total, $weights)), json_encode([$total, $weights]));
            }
        }

        // Nothing to spread over, nothing allocated.
        $this->assertSame([0, 0], Money::allocate(500, [0, 0]));
    }

    public function test_the_discount_is_split_over_the_lines_and_stored_on_them(): void
    {
        [, , , , , $invoice, $shirtLine, $hatLine] = $this->sellShirtAndHatWithInvoiceDiscount(100);

        $this->assertSame('75.00', (string) $shirtLine->invoice_discount_share);
        $this->assertSame('25.00', (string) $hatLine->invoice_discount_share);
        $this->assertSame('500.00', (string) $invoice->total);

        // The shares add up to the discount, to the piastre.
        $this->assertSame(
            Money::toMinor($invoice->discount_amount),
            $invoice->items->sum(fn ($i) => Money::toMinor($i->invoice_discount_share)),
        );
    }

    public function test_no_piastre_is_lost_when_the_discount_does_not_divide_evenly(): void
    {
        $this->tenant();
        [$branch, $device] = $this->shopFloor();
        $manager = $this->manager($branch);

        $variants = ProductVariant::factory()->count(3)->create(['price' => 100]);
        $variants->each(fn ($v) => $this->giveStock($v, $branch, 5));

        $this->actingAs($manager)->postJson('/api/invoices', [
            'device_id' => $device->id,
            'items' => $variants->map(fn ($v) => ['variant_id' => $v->id, 'quantity' => 1])->all(),
            'discount_amount' => 100,
        ])->assertCreated();

        $shares = Invoice::firstOrFail()->items->map(fn ($i) => Money::toMinor($i->invoice_discount_share))->sort()->values()->all();

        $this->assertSame(10000, array_sum($shares));
        $this->assertSame([3333, 3333, 3334], $shares);
    }

    public function test_a_return_refunds_what_was_paid_after_the_invoice_discount(): void
    {
        [, , $device, , $manager, $invoice, $shirtLine] = $this->sellShirtAndHatWithInvoiceDiscount(100);

        // 450 - 75, not 450: the customer never paid the 75.
        $this->actingAs($manager)->postJson('/api/returns', [
            'device_id' => $device->id,
            'invoice_id' => $invoice->id,
            'items' => [['invoice_item_id' => $shirtLine->id, 'quantity' => 1]],
        ])
            ->assertCreated()
            ->assertJsonPath('data.refund_amount', '375.00');
    }

    public function test_returning_everything_refunds_exactly_the_invoice_total(): void
    {
        [, , $device, , $manager, $invoice, $shirtLine, $hatLine] = $this->sellShirtAndHatWithInvoiceDiscount(100);

        $this->actingAs($manager)->postJson('/api/returns', [
            'device_id' => $device->id,
            'invoice_id' => $invoice->id,
            'items' => [
                ['invoice_item_id' => $shirtLine->id, 'quantity' => 1],
                ['invoice_item_id' => $hatLine->id, 'quantity' => 1],
            ],
        ])
            ->assertCreated()
            ->assertJsonPath('data.refund_amount', (string) $invoice->total);
    }

    public function test_the_refund_comes_from_the_stored_share_and_is_not_recomputed(): void
    {
        [, , $device, , $manager, $invoice, $shirtLine] = $this->sellShirtAndHatWithInvoiceDiscount(100);

        // Whatever happens to the invoice's own figures later, the line keeps
        // the share it was given at the till.
        $invoice->forceFill(['discount_amount' => 0])->save();

        $this->actingAs($manager)->postJson('/api/returns', [
            'device_id' => $device->id,
            'invoice_id' => $invoice->id,
            'items' => [['invoice_item_id' => $shirtLine->id, 'quantity' => 1]],
        ])->assertJsonPath('data.refund_amount', '375.00');
    }

    public function test_an_exchange_credits_what_was_paid_not_the_list_price(): void
    {
        [, , $device, , $manager, $invoice, $shirtLine] = $this->sellShirtAndHatWithInvoiceDiscount(100);

        $large = ProductVariant::factory()->create(['price' => 650]);
        $this->giveStock($large, $manager->branch_id, 3);

        $this->actingAs($manager)->postJson('/api/returns', [
            'device_id' => $device->id,
            'invoice_id' => $invoice->id,
            'items' => [['invoice_item_id' => $shirtLine->id, 'quantity' => 1]],
            'exchange_items' => [['variant_id' => $large->id, 'quantity' => 1]],
        ])
            ->assertCreated()
            ->assertJsonPath('data.refund_amount', '375.00')
            ->assertJsonPath('meta.difference', '275.00')   // 650 - 375, not 650 - 450
            ->assertJsonPath('meta.customer_pays', true);
    }

    public function test_the_invoice_resource_shows_the_share_and_what_was_paid(): void
    {
        [, , , , $manager, $invoice] = $this->sellShirtAndHatWithInvoiceDiscount(100);

        $items = $this->actingAs($manager)->getJson('/api/invoices/'.$invoice->id)
            ->assertOk()
            ->json('data.items');

        $byPaid = collect($items)->pluck('paid')->sort()->values()->all();

        $this->assertSame(['125.00', '375.00'], $byPaid);
    }

    public function test_the_best_sellers_by_value_are_after_both_discounts(): void
    {
        [, , , , $manager] = $this->sellShirtAndHatWithInvoiceDiscount(100);

        $response = $this->actingAs($manager)
            ->getJson('/api/reports/sales?from='.now()->toDateString().'&to='.now()->toDateString())
            ->assertOk();

        // 450 - 75 and 150 - 25; together they are the invoice total.
        $response->assertJsonPath('data.top_by_value.0.total', '375.00')
            ->assertJsonPath('data.top_by_value.1.total', '125.00')
            ->assertJsonPath('data.total', '500.00');
    }

    public function test_a_seller_is_refused_an_invoice_discount_until_the_shop_allows_it(): void
    {
        $business = $this->tenant();
        [$branch, $device, $seller] = $this->shopFloor();
        $shirt = ProductVariant::factory()->create(['price' => 450]);
        $this->giveStock($shirt, $branch, 5);

        $payload = [
            'device_id' => $device->id,
            'items' => [['variant_id' => $shirt->id, 'quantity' => 1]],
            'discount_amount' => 10,
        ];

        $this->actingAs($seller)->postJson('/api/invoices', $payload)
            ->assertForbidden()
            ->assertJsonPath('code', 'invoice_discount_not_allowed');

        $this->assertSame(0, Invoice::count());

        // The same switch that opens line discounts opens this one.
        $business->forceFill(['settings' => ['discounts' => ['sellers_allowed' => true, 'max_percent' => 20]]])->save();

        $this->actingAs($seller->fresh())->postJson('/api/invoices', $payload)->assertCreated();
    }

    public function test_a_seller_cannot_discount_the_invoice_past_the_maximum_percent(): void
    {
        $this->tenant(['settings' => ['discounts' => ['sellers_allowed' => true, 'max_percent' => 20]]]);
        [$branch, $device, $seller] = $this->shopFloor();
        $shirt = ProductVariant::factory()->create(['price' => 450]);
        $this->giveStock($shirt, $branch, 5);

        $send = fn ($amount) => $this->actingAs($seller)->postJson('/api/invoices', [
            'device_id' => $device->id,
            'items' => [['variant_id' => $shirt->id, 'quantity' => 1]],
            'discount_amount' => $amount,
        ]);

        // Exactly 20% of 450 passes; a piastre more does not.
        $send(90)->assertCreated();
        $send(90.01)->assertForbidden()->assertJsonPath('code', 'invoice_discount_over_limit');
    }

    public function test_the_sellers_cap_is_on_the_line_and_invoice_discounts_together(): void
    {
        $this->tenant(['settings' => ['discounts' => ['sellers_allowed' => true, 'max_percent' => 20]]]);
        [$branch, $device, $seller] = $this->shopFloor();
        $shirt = ProductVariant::factory()->create(['price' => 450]);
        $hat = ProductVariant::factory()->create(['price' => 150]);
        $this->giveStock($shirt, $branch, 5);
        $this->giveStock($hat, $branch, 5);

        // Gross 600, so 20% is 120 in total. 90 off the shirt line (its own 20%)
        // leaves room for 30 off the invoice and not a piastre more, even
        // though 30 is far below 20% of the 510 subtotal.
        $send = fn ($invoiceDiscount) => $this->actingAs($seller)->postJson('/api/invoices', [
            'device_id' => $device->id,
            'items' => [
                ['variant_id' => $shirt->id, 'quantity' => 1, 'discount_amount' => 90],
                ['variant_id' => $hat->id, 'quantity' => 1],
            ],
            'discount_amount' => $invoiceDiscount,
        ]);

        $send(30.01)->assertForbidden()->assertJsonPath('code', 'invoice_discount_over_limit');
        $this->assertSame(0, Invoice::count());

        $send(30)->assertCreated();
    }

    public function test_a_manager_has_no_cap_on_the_invoice_discount(): void
    {
        $this->tenant(['settings' => ['discounts' => ['sellers_allowed' => false, 'max_percent' => 5]]]);
        [$branch, $device] = $this->shopFloor();
        $manager = $this->manager($branch);
        $shirt = ProductVariant::factory()->create(['price' => 450]);
        $this->giveStock($shirt, $branch, 5);

        // 50% off, against a 5% seller cap and a switch that is off.
        $this->actingAs($manager)->postJson('/api/invoices', [
            'device_id' => $device->id,
            'items' => [['variant_id' => $shirt->id, 'quantity' => 1]],
            'discount_amount' => 225,
        ])->assertCreated()->assertJsonPath('data.total', '225.00');
    }

    public function test_a_shop_keeps_its_discount_setting_when_the_key_is_renamed(): void
    {
        $business = $this->tenant(['settings' => [
            'currency' => 'EGP',
            'line_discounts' => ['sellers_allowed' => true, 'max_percent' => 35],
        ]]);
        $untouched = $this->tenant(['settings' => ['currency' => 'USD']]);

        $migration = require database_path('migrations/2026_11_16_000400_rename_line_discounts_setting_to_discounts.php');
        $migration->up();

        $this->assertTrue($business->fresh()->sellersMayDiscount());
        $this->assertSame(35, $business->fresh()->discountMaxPercent());
        $this->assertNull($business->fresh()->setting('line_discounts'));
        $this->assertSame('EGP', $business->fresh()->currency());

        // A shop that never set it is left exactly as it was.
        $this->assertSame(['currency' => 'USD'], $untouched->fresh()->settings);
    }

    public function test_a_line_from_before_the_split_refunds_as_it_always_did(): void
    {
        [, , , , , , $shirtLine] = $this->sellShirtAndHatWithInvoiceDiscount(0);

        // What the migration leaves on a row of an invoice with no invoice discount.
        $shirtLine->forceFill(['invoice_discount_share' => 0])->save();

        $this->assertInstanceOf(InvoiceItem::class, $shirtLine);
        $this->assertSame(45000, $shirtLine->fresh()->refundMinorFor(1));
    }
}
