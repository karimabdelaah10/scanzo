<?php

namespace Tests\Feature;

use App\Enums\BusinessStatus;
use App\Enums\StockMovementType;
use App\Models\Device;
use App\Models\Invoice;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Services\StockLedger;
use App\Support\BusinessContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SellingTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_sale_writes_the_invoice_its_lines_and_the_stock_movements(): void
    {
        // The shop lets sellers discount (see InvoiceDiscountTest for the
        // refusals); this test is about what a sale writes.
        $this->tenant(['settings' => ['discounts' => ['sellers_allowed' => true, 'max_percent' => 20]]]);
        [$branch, $device, $seller] = $this->shopFloor();

        $shirt = ProductVariant::factory()->create(['price' => 450]);
        $jeans = ProductVariant::factory()->create(['price' => 650]);
        $this->giveStock($shirt, $branch, 6);
        $this->giveStock($jeans, $branch, 4);

        $response = $this->actingAs($seller)->postJson('/api/invoices', [
            'device_id' => $device->id,
            'items' => [
                ['variant_id' => $shirt->id, 'quantity' => 1],
                ['variant_id' => $jeans->id, 'quantity' => 2],
            ],
            'discount_amount' => 50,
            'payment_method' => 'cash',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.subtotal', '1750.00')   // 450 + 1300
            ->assertJsonPath('data.discount_amount', '50.00')
            ->assertJsonPath('data.total', '1700.00')
            ->assertJsonCount(2, 'data.items');

        $invoice = Invoice::firstOrFail();

        $this->assertSame($branch->id, $invoice->branch_id);
        $this->assertSame($device->id, $invoice->device_id);
        $this->assertSame($seller->id, $invoice->user_id);

        // The line keeps its own copy of name, sku and price.
        $line = $invoice->items()->where('variant_id', $shirt->id)->firstOrFail();
        $this->assertSame($shirt->sku, $line->sku);
        $this->assertSame('450.00', (string) $line->unit_price);

        // Stock came off the shelf, and only through the ledger.
        $ledger = app(StockLedger::class);
        $this->assertSame(5, $ledger->balance($shirt->id, $branch->id));
        $this->assertSame(2, $ledger->balance($jeans->id, $branch->id));

        $this->assertSame(2, StockMovement::where('type', StockMovementType::Sale)->count());
        $this->assertSame(
            $invoice->id,
            StockMovement::where('type', StockMovementType::Sale)->first()->reference_id
        );
    }

    public function test_the_price_comes_from_the_catalogue_and_not_from_the_request(): void
    {
        $this->tenant();
        [$branch, $device, $seller] = $this->shopFloor();

        $variant = ProductVariant::factory()->create(['price' => 450]);
        $this->giveStock($variant, $branch, 3);

        $this->actingAs($seller)->postJson('/api/invoices', [
            'device_id' => $device->id,
            // A till running an old price list, or a tampered client.
            'items' => [['variant_id' => $variant->id, 'quantity' => 1, 'unit_price' => '1.00', 'price' => '1.00']],
        ])
            ->assertCreated()
            ->assertJsonPath('data.total', '450.00');
    }

    public function test_selling_more_than_the_shelf_holds_is_refused_and_writes_nothing(): void
    {
        $this->tenant();
        [$branch, $device, $seller] = $this->shopFloor();

        $variant = ProductVariant::factory()->create();
        $this->giveStock($variant, $branch, 2);

        $this->actingAs($seller)->postJson('/api/invoices', [
            'device_id' => $device->id,
            'items' => [['variant_id' => $variant->id, 'quantity' => 3]],
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'out_of_stock')
            ->assertJsonPath('context.available', 2)
            ->assertJsonPath('context.requested', 3);

        $this->assertSame(0, Invoice::count());
        // The opening stock movement is the only one left.
        $this->assertSame(1, StockMovement::count());
        $this->assertSame(2, app(StockLedger::class)->balance($variant->id, $branch->id));
    }

    public function test_repeated_scans_of_one_item_become_a_single_line(): void
    {
        $this->tenant();
        [$branch, $device, $seller] = $this->shopFloor();

        $variant = ProductVariant::factory()->create(['price' => 100]);
        $this->giveStock($variant, $branch, 5);

        $this->actingAs($seller)->postJson('/api/invoices', [
            'device_id' => $device->id,
            'items' => [
                ['variant_id' => $variant->id, 'quantity' => 1],
                ['variant_id' => $variant->id, 'quantity' => 2],
            ],
        ])
            ->assertCreated()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.quantity', 3)
            ->assertJsonPath('data.total', '300.00');
    }

    public function test_a_resent_sale_does_not_become_two_invoices(): void
    {
        // What makes the offline sync safe to retry.
        $this->tenant();
        [$branch, $device, $seller] = $this->shopFloor();

        $variant = ProductVariant::factory()->create(['price' => 200]);
        $this->giveStock($variant, $branch, 5);

        $id = (string) Str::orderedUuid();

        $payload = [
            'id' => $id,
            'device_id' => $device->id,
            'items' => [['variant_id' => $variant->id, 'quantity' => 1]],
        ];

        $first = $this->actingAs($seller)->postJson('/api/invoices', $payload)->assertCreated();
        $second = $this->actingAs($seller)->postJson('/api/invoices', $payload)->assertCreated();

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame($id, $first->json('data.id'));
        $this->assertSame(1, Invoice::count());
        $this->assertSame(4, app(StockLedger::class)->balance($variant->id, $branch->id));
    }

    public function test_invoice_numbers_run_in_sequence_per_device(): void
    {
        $this->tenant();
        [$branch, $deviceA, $seller] = $this->shopFloor();
        $deviceB = Device::factory()->create(['branch_id' => $branch->id, 'code' => 'B9']);

        $variant = ProductVariant::factory()->create(['price' => 50]);
        $this->giveStock($variant, $branch, 20);

        $items = [['variant_id' => $variant->id, 'quantity' => 1]];

        $first = $this->actingAs($seller)->postJson('/api/invoices', ['device_id' => $deviceA->id, 'items' => $items]);
        $second = $this->actingAs($seller)->postJson('/api/invoices', ['device_id' => $deviceA->id, 'items' => $items]);
        $onB = $this->actingAs($seller)->postJson('/api/invoices', ['device_id' => $deviceB->id, 'items' => $items]);

        $this->assertSame($deviceA->code.'-000001', $first->json('data.number'));
        $this->assertSame($deviceA->code.'-000002', $second->json('data.number'));
        // Each device counts for itself, which is what keeps numbers unique
        // when tills sell offline at the same time.
        $this->assertSame('B9-000001', $onB->json('data.number'));
    }

    public function test_a_discount_bigger_than_the_sale_is_refused(): void
    {
        $this->tenant();
        [$branch, $device, $seller] = $this->shopFloor();

        $variant = ProductVariant::factory()->create(['price' => 100]);
        $this->giveStock($variant, $branch, 2);

        $this->actingAs($this->manager($branch))->postJson('/api/invoices', [
            'device_id' => $device->id,
            'items' => [['variant_id' => $variant->id, 'quantity' => 1]],
            'discount_amount' => 150,
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'discount_too_large');

        $this->assertSame(0, Invoice::count());
    }

    public function test_a_suspended_business_cannot_sell(): void
    {
        $this->tenant(['status' => BusinessStatus::Suspended]);
        [$branch, $device, $seller] = $this->shopFloor();

        $variant = ProductVariant::factory()->create();
        $this->giveStock($variant, $branch, 5);

        $this->actingAs($seller)->postJson('/api/invoices', [
            'device_id' => $device->id,
            'items' => [['variant_id' => $variant->id, 'quantity' => 1]],
        ])
            ->assertForbidden()
            ->assertJsonPath('code', 'business_suspended');
    }

    public function test_a_device_that_has_never_reached_the_server_cannot_sell(): void
    {
        $this->tenant();
        [$branch, , $seller] = $this->shopFloor();
        $device = Device::factory()->neverSynced()->create(['branch_id' => $branch->id]);

        $variant = ProductVariant::factory()->create();
        $this->giveStock($variant, $branch, 5);

        $this->actingAs($seller)->postJson('/api/invoices', [
            'device_id' => $device->id,
            'items' => [['variant_id' => $variant->id, 'quantity' => 1]],
        ])
            ->assertForbidden()
            ->assertJsonPath('code', 'device_out_of_grace');
    }

    public function test_a_till_from_another_business_is_not_reachable(): void
    {
        $this->tenant();
        [$otherBranch] = $this->shopFloor();
        $otherDevice = Device::factory()->create(['branch_id' => $otherBranch->id]);

        $this->tenant();
        [$branch, , $seller] = $this->shopFloor();
        $variant = ProductVariant::factory()->create();
        $this->giveStock($variant, $branch, 5);

        $this->actingAs($seller)->postJson('/api/invoices', [
            'device_id' => $otherDevice->id,
            'items' => [['variant_id' => $variant->id, 'quantity' => 1]],
        ])->assertStatus(422); // fails validation: not a device of this tenant

        $this->assertSame(0, Invoice::count());
    }

    public function test_a_card_sale_is_stored_and_returned_as_card(): void
    {
        $this->tenant();
        [$branch, $device, $seller] = $this->shopFloor();

        $variant = ProductVariant::factory()->create(['price' => 450]);
        $this->giveStock($variant, $branch, 5);

        $this->actingAs($seller)->postJson('/api/invoices', [
            'device_id' => $device->id,
            'items' => [['variant_id' => $variant->id, 'quantity' => 1]],
            'payment_method' => 'card',
        ])->assertCreated()
            ->assertJsonPath('data.payment_method', 'card')
            ->assertJsonPath('data.payment_method_label', 'فيزا');

        $this->assertSame('card', Invoice::firstOrFail()->payment_method->value);
    }

    public function test_an_unknown_payment_method_is_refused_not_turned_into_cash(): void
    {
        $this->tenant();
        [$branch, $device, $seller] = $this->shopFloor();

        $variant = ProductVariant::factory()->create(['price' => 450]);
        $this->giveStock($variant, $branch, 5);

        foreach (['haha', 'mixed'] as $method) {
            $this->actingAs($seller)->postJson('/api/invoices', [
                'device_id' => $device->id,
                'items' => [['variant_id' => $variant->id, 'quantity' => 1]],
                'payment_method' => $method,
            ])->assertStatus(422)->assertJsonValidationErrors('payment_method');
        }

        $this->assertSame(0, Invoice::count());
    }

    public function test_an_invoice_comes_with_what_the_receipt_needs(): void
    {
        $this->tenant();
        [$branch, $device, $seller] = $this->shopFloor();

        $variant = ProductVariant::factory()->create(['price' => 450]);
        $this->giveStock($variant, $branch, 5);

        $seller->business->update(['settings' => ['receipt' => ['footer' => 'شكراً لزيارتكم']]]);

        $id = $this->actingAs($seller)->postJson('/api/invoices', [
            'device_id' => $device->id,
            'items' => [['variant_id' => $variant->id, 'quantity' => 1]],
        ])->assertCreated()->json('data.id');

        // The show route is the one that must not lazy load: it runs with
        // lazy loading prevented, so a relation left unloaded would throw.
        $this->actingAs($seller)->getJson("/api/invoices/{$id}")
            ->assertOk()
            ->assertJsonPath('data.business.name', $seller->business->name)
            ->assertJsonPath('data.business.receipt_footer', 'شكراً لزيارتكم')
            ->assertJsonPath('data.business.receipt_header', null)
            ->assertJsonPath('data.branch.name', $branch->name)
            ->assertJsonPath('data.seller.name', $seller->name)
            // The seller is a name and nothing else.
            ->assertJsonMissingPath('data.seller.email')
            ->assertJsonMissingPath('data.seller.role');
    }

    protected function tearDown(): void
    {
        app(BusinessContext::class)->forgetBusiness();

        parent::tearDown();
    }
}
