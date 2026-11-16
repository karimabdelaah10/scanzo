<?php

namespace Tests\Feature;

use App\Models\ProductVariant;
use App\Models\SaleReturn;
use App\Models\SaleReturnItem;
use App\Services\StockLedger;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A discount on one line ("this shirt is 20% off today").
 *
 * The rule that matters most: a return refunds what was PAID, not the list
 * price. A 450 shirt sold with 100 off was paid at 350, and 350 is what goes
 * back. Everything else here supports that.
 */
class LineDiscountTest extends TestCase
{
    use RefreshDatabase;

    private function sellOneDiscountedShirt(int $quantity = 1, float $discount = 100): array
    {
        $business = $this->tenant();
        [$branch, $device, $seller] = $this->shopFloor();
        $manager = $this->manager($branch);

        $shirt = ProductVariant::factory()->create(['price' => 450]);
        $this->giveStock($shirt, $branch, 10);

        $this->actingAs($manager)->postJson('/api/invoices', [
            'device_id' => $device->id,
            'items' => [['variant_id' => $shirt->id, 'quantity' => $quantity, 'discount_amount' => $discount]],
        ])->assertCreated();

        $invoice = \App\Models\Invoice::firstOrFail();

        return [$business, $branch, $device, $seller, $manager, $shirt, $invoice, $invoice->items()->firstOrFail()];
    }

    public function test_a_sale_with_a_line_discount_stores_an_amount_and_totals_after_it(): void
    {
        [, , $device, , $manager, $shirt] = $this->sellOneDiscountedShirt(quantity: 1, discount: 100);

        // Second sale on the same till: one discounted line, one not.
        $jeans = ProductVariant::factory()->create(['price' => 650]);
        $this->giveStock($jeans, $device->branch_id, 5);

        $this->actingAs($manager)->postJson('/api/invoices', [
            'device_id' => $device->id,
            'items' => [
                ['variant_id' => $shirt->id, 'quantity' => 2, 'discount_amount' => 90],
                ['variant_id' => $jeans->id, 'quantity' => 1],
            ],
        ])
            ->assertCreated()
            ->assertJsonPath('data.subtotal', '1460.00')      // (900 − 90) + 650
            ->assertJsonPath('data.discount_amount', '0.00')
            ->assertJsonPath('data.total', '1460.00')
            ->assertJsonPath('data.items.0.unit_price', '450.00')
            ->assertJsonPath('data.items.0.discount_amount', '90.00')
            ->assertJsonPath('data.items.0.line_total', '810.00')
            ->assertJsonPath('data.items.1.discount_amount', '0.00')
            ->assertJsonPath('data.items.1.line_total', '650.00');
    }

    public function test_a_return_of_a_discounted_line_refunds_what_was_paid_not_the_list_price(): void
    {
        [, , $device, , $manager, $shirt, $invoice, $line] = $this->sellOneDiscountedShirt(quantity: 1, discount: 100);

        // The shirt was 450, with 100 off: the customer paid 350.
        $this->assertSame('350.00', (string) $line->line_total);

        // And the price moves afterwards; the refund must not follow it either.
        $shirt->forceFill(['price' => 999])->save();

        $this->actingAs($manager)->postJson('/api/returns', [
            'device_id' => $device->id,
            'invoice_id' => $invoice->id,
            'items' => [['invoice_item_id' => $line->id, 'quantity' => 1]],
        ])
            ->assertCreated()
            ->assertJsonPath('data.refund_amount', '350.00')       // not 450.00
            ->assertJsonPath('data.items.0.line_total', '350.00')
            ->assertJsonPath('data.items.0.amount', '350.00');

        $this->assertSame('350.00', (string) SaleReturn::firstOrFail()->refund_amount);
        $this->assertSame('350.00', (string) SaleReturnItem::firstOrFail()->amount);
    }

    public function test_returning_a_discounted_line_in_pieces_never_refunds_more_than_was_paid(): void
    {
        // 3 shirts for 1350, minus 1000 = 350 paid: not divisible by 3.
        [, , $device, , $manager, , $invoice, $line] = $this->sellOneDiscountedShirt(quantity: 3, discount: 1000);

        $this->assertSame('350.00', (string) $line->line_total);

        $refunds = [];

        foreach ([1, 1, 1] as $ignored) {
            $res = $this->actingAs($manager)->postJson('/api/returns', [
                'device_id' => $device->id,
                'invoice_id' => $invoice->id,
                'items' => [['invoice_item_id' => $line->id, 'quantity' => 1]],
            ])->assertCreated();

            $refunds[] = Money::toMinor($res->json('data.refund_amount'));
        }

        // 116.67 + 116.66 + 116.67 — to the piastre, exactly what was paid.
        $this->assertSame(35000, array_sum($refunds));
        $this->assertSame(35000, Money::toMinor(SaleReturn::sum('refund_amount')));
    }

    public function test_the_whole_discounted_line_back_at_once_refunds_the_paid_total(): void
    {
        [, , $device, , $manager, , $invoice, $line] = $this->sellOneDiscountedShirt(quantity: 3, discount: 1000);

        $this->actingAs($manager)->postJson('/api/returns', [
            'device_id' => $device->id,
            'invoice_id' => $invoice->id,
            'items' => [['invoice_item_id' => $line->id, 'quantity' => 3]],
        ])->assertCreated()->assertJsonPath('data.refund_amount', '350.00');
    }

    public function test_voiding_an_invoice_with_a_line_discount_keeps_its_numbers_and_restores_stock(): void
    {
        [, $branch, , , $manager, $shirt, $invoice, $line] = $this->sellOneDiscountedShirt(quantity: 2, discount: 100);

        $this->assertSame(8, app(StockLedger::class)->balance($shirt->id, $branch->id));

        $this->actingAs($manager)->postJson("/api/invoices/{$invoice->id}/void", ['reason' => 'غلطة'])
            ->assertOk()
            ->assertJsonPath('data.status', 'voided')
            // What was sold and charged stays as printed: 2 × 450 − 100.
            ->assertJsonPath('data.total', '800.00')
            ->assertJsonPath('data.items.0.discount_amount', '100.00')
            ->assertJsonPath('data.items.0.line_total', '800.00');

        $this->assertSame(10, app(StockLedger::class)->balance($shirt->id, $branch->id));
        $this->assertSame('800.00', (string) $line->fresh()->line_total);

        // A cancelled sale is no revenue, and a void pays nothing out.
        $this->actingAs($manager)
            ->getJson('/api/reports/sales?from='.now()->toDateString().'&to='.now()->toDateString())
            ->assertOk()
            ->assertJsonPath('data.invoices', 0)
            ->assertJsonPath('data.total', '0.00')
            ->assertJsonPath('data.returns.total', '0.00');
    }

    public function test_the_line_discount_comes_first_and_the_invoice_discount_is_taken_from_what_is_left(): void
    {
        $this->tenant();
        [$branch, $device] = $this->shopFloor();
        $manager = $this->manager($branch);

        $shirt = ProductVariant::factory()->create(['price' => 450]);
        $jeans = ProductVariant::factory()->create(['price' => 650]);
        $this->giveStock($shirt, $branch, 5);
        $this->giveStock($jeans, $branch, 5);

        // 450 − 100 = 350, + 650 = 1000, then 50 off the invoice = 950.
        // Not 1100 − 100 − 50 applied twice, and not a discount on the 1100.
        $this->actingAs($manager)->postJson('/api/invoices', [
            'device_id' => $device->id,
            'items' => [
                ['variant_id' => $shirt->id, 'quantity' => 1, 'discount_amount' => 100],
                ['variant_id' => $jeans->id, 'quantity' => 1],
            ],
            'discount_amount' => 50,
        ])
            ->assertCreated()
            ->assertJsonPath('data.subtotal', '1000.00')
            ->assertJsonPath('data.discount_amount', '50.00')
            ->assertJsonPath('data.total', '950.00');
    }

    public function test_a_discount_bigger_than_the_line_is_refused_and_writes_nothing(): void
    {
        $this->tenant();
        [$branch, $device] = $this->shopFloor();
        $manager = $this->manager($branch);

        $shirt = ProductVariant::factory()->create(['price' => 450]);
        $this->giveStock($shirt, $branch, 5);

        // The invoice discount rule would let this through: the OTHER line is big enough.
        $jeans = ProductVariant::factory()->create(['price' => 5000]);
        $this->giveStock($jeans, $branch, 5);

        $this->actingAs($manager)->postJson('/api/invoices', [
            'device_id' => $device->id,
            'items' => [
                ['variant_id' => $shirt->id, 'quantity' => 1, 'discount_amount' => 450.01],
                ['variant_id' => $jeans->id, 'quantity' => 1],
            ],
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'line_discount_too_large');

        $this->assertSame(0, \App\Models\Invoice::count());
        $this->assertSame(5, app(StockLedger::class)->balance($shirt->id, $branch->id));
    }

    public function test_a_seller_is_refused_a_line_discount_until_the_shop_allows_it(): void
    {
        $business = $this->tenant();
        [$branch, $device, $seller] = $this->shopFloor();

        $shirt = ProductVariant::factory()->create(['price' => 450]);
        $this->giveStock($shirt, $branch, 5);

        $payload = [
            'device_id' => $device->id,
            'items' => [['variant_id' => $shirt->id, 'quantity' => 1, 'discount_amount' => 50]],
        ];

        // Default: closed.
        $this->actingAs($seller)->postJson('/api/invoices', $payload)
            ->assertForbidden()
            ->assertJsonPath('code', 'line_discount_not_allowed');

        $this->assertSame(0, \App\Models\Invoice::count());

        // A seller selling WITHOUT a discount is untouched by the setting.
        $this->actingAs($seller)->postJson('/api/invoices', [
            'device_id' => $device->id,
            'items' => [['variant_id' => $shirt->id, 'quantity' => 1]],
        ])->assertCreated();

        // The owner opens it; the till is told, so it shows the control.
        $business->forceFill(['settings' => [
            'discounts' => ['sellers_allowed' => true, 'max_percent' => 20],
        ]])->save();

        $this->actingAs($seller->fresh())->getJson('/api/me')
            ->assertJsonPath('business.can_discount', true)
            ->assertJsonPath('business.discount_max_percent', 20);

        // 50 of 450 is 11.1%: inside the 20% cap.
        $this->actingAs($seller->fresh())->postJson('/api/invoices', $payload)
            ->assertCreated()
            ->assertJsonPath('data.items.0.line_total', '400.00');
    }

    public function test_a_seller_cannot_go_past_the_maximum_percent(): void
    {
        $this->tenant(['settings' => [
            'discounts' => ['sellers_allowed' => true, 'max_percent' => 20],
        ]]);
        [$branch, $device, $seller] = $this->shopFloor();

        $shirt = ProductVariant::factory()->create(['price' => 450]);
        $this->giveStock($shirt, $branch, 5);

        $send = fn ($amount) => $this->actingAs($seller)->postJson('/api/invoices', [
            'device_id' => $device->id,
            'items' => [['variant_id' => $shirt->id, 'quantity' => 1, 'discount_amount' => $amount]],
        ]);

        // Exactly 20% of 450 passes; a piastre more does not.
        $send(90)->assertCreated();
        $send(90.01)->assertForbidden()->assertJsonPath('code', 'line_discount_over_limit');
    }

    public function test_the_settings_screen_can_read_and_change_the_discount_rules(): void
    {
        $business = $this->tenant();
        $owner = \App\Models\User::factory()->owner()->create();

        $this->actingAs($owner)->getJson('/api/settings')
            ->assertJsonPath('data.discounts.sellers_allowed', false)
            ->assertJsonPath('data.discounts.max_percent', 20);

        $this->actingAs($owner)->patchJson('/api/settings', [
            'discounts' => ['sellers_allowed' => true, 'max_percent' => 35],
        ])
            ->assertOk()
            ->assertJsonPath('data.discounts.sellers_allowed', true)
            ->assertJsonPath('data.discounts.max_percent', 35);

        // And the rule the till and the server enforce is the one that was saved.
        $this->assertTrue($business->fresh()->sellersMayDiscount());
        $this->assertSame(35, $business->fresh()->discountMaxPercent());
    }

    public function test_the_best_sellers_by_value_are_after_the_line_discount(): void
    {
        [, , , , $manager] = $this->sellOneDiscountedShirt(quantity: 1, discount: 100);

        $this->actingAs($manager)
            ->getJson('/api/reports/sales?from='.now()->toDateString().'&to='.now()->toDateString())
            ->assertOk()
            ->assertJsonPath('data.top_by_value.0.total', '350.00')   // not 450.00
            ->assertJsonPath('data.total', '350.00');
    }

    public function test_an_old_line_without_a_discount_still_adds_up(): void
    {
        [, , , , , , $invoice, $line] = $this->sellOneDiscountedShirt(quantity: 2, discount: 0);

        // What the migration leaves on a pre-existing row: a zero, not a null.
        $line->forceFill(['discount_amount' => 0])->save();

        $this->assertSame('0.00', (string) $line->fresh()->discount_amount);
        $this->assertSame('900.00', (string) $line->fresh()->line_total);
        $this->assertSame(90000, $line->fresh()->refundMinorFor(2));
    }
}
