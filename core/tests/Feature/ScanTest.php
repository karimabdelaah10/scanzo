<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Support\BusinessContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScanTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_scan_returns_the_item_and_what_is_left_in_this_branch(): void
    {
        $this->tenant();
        [$branch, , $seller] = $this->shopFloor();

        $product = Product::factory()->create(['name' => 'قميص كتان رجالي']);
        $variant = ProductVariant::factory()->create([
            'product_id' => $product->id,
            'sku' => 'SKU-10452',
            'size' => 'L',
            'color' => 'أزرق',
            'price' => 450,
        ]);

        $this->giveStock($variant, $branch, 6);

        $this->actingAs($seller)
            ->getJson("/api/scan?code=SKU-10452&branch_id={$branch->id}")
            ->assertOk()
            ->assertJsonPath('data.sku', 'SKU-10452')
            ->assertJsonPath('data.product_name', 'قميص كتان رجالي')
            ->assertJsonPath('data.label', 'L · أزرق')
            ->assertJsonPath('data.price', '450.00')
            ->assertJsonPath('data.available', 6);
    }

    public function test_a_scan_also_resolves_the_one_dimensional_barcode(): void
    {
        $this->tenant();
        [$branch, , $seller] = $this->shopFloor();

        $variant = ProductVariant::factory()->create(['barcode' => '6201234567890']);
        $this->giveStock($variant, $branch, 2);

        $this->actingAs($seller)
            ->getJson("/api/scan?code=6201234567890&branch_id={$branch->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $variant->id);
    }

    public function test_typing_the_number_alone_finds_the_generated_code(): void
    {
        // What someone gives you when the label is scuffed and they can only
        // read the digits, and what a scanner set to strip the prefix sends.
        $this->tenant();
        [$branch, , $seller] = $this->shopFloor();

        $variant = ProductVariant::factory()->create(['sku' => 'SKU-10452']);
        $this->giveStock($variant, $branch, 4);

        $this->actingAs($seller)
            ->getJson("/api/scan?code=10452&branch_id={$branch->id}")
            ->assertOk()
            ->assertJsonPath('data.sku', 'SKU-10452');
    }

    public function test_a_barcode_of_digits_wins_over_the_sku_fallback(): void
    {
        // The fallback must never shadow a real code: a numeric barcode is
        // matched as itself, not reinterpreted.
        $this->tenant();
        [$branch, , $seller] = $this->shopFloor();

        $barcoded = ProductVariant::factory()->create([
            'sku' => 'SKU-70001',
            'barcode' => '10452',
        ]);
        $skuOnly = ProductVariant::factory()->create(['sku' => 'SKU-10452', 'barcode' => null]);

        $this->giveStock($barcoded, $branch, 2);
        $this->giveStock($skuOnly, $branch, 2);

        $this->actingAs($seller)
            ->getJson("/api/scan?code=10452&branch_id={$branch->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $barcoded->id);
    }

    public function test_the_margin_is_never_sent_to_the_till(): void
    {
        $this->tenant();
        [$branch, , $seller] = $this->shopFloor();

        $variant = ProductVariant::factory()->create(['price' => 450, 'cost' => 260]);
        $this->giveStock($variant, $branch, 1);

        $this->actingAs($seller)
            ->getJson("/api/scan?code={$variant->sku}&branch_id={$branch->id}")
            ->assertOk()
            ->assertJsonMissingPath('data.cost');
    }

    public function test_an_unknown_code_is_a_not_found_with_a_usable_error_code(): void
    {
        $this->tenant();
        [$branch, , $seller] = $this->shopFloor();

        $this->actingAs($seller)
            ->getJson("/api/scan?code=SKU-99999&branch_id={$branch->id}")
            ->assertNotFound()
            ->assertJsonPath('code', 'variant_not_found');
    }

    public function test_an_item_taken_off_sale_is_refused(): void
    {
        $this->tenant();
        [$branch, , $seller] = $this->shopFloor();

        $variant = ProductVariant::factory()->create(['is_active' => false]);

        $this->actingAs($seller)
            ->getJson("/api/scan?code={$variant->sku}&branch_id={$branch->id}")
            ->assertStatus(422)
            ->assertJsonPath('code', 'variant_inactive');
    }

    public function test_a_label_from_another_business_resolves_to_nothing(): void
    {
        // The whole point of the tenant scope: the same code in two shops must
        // never cross over.
        $other = $this->tenant();
        $otherVariant = ProductVariant::factory()->create(['sku' => 'SKU-10452']);

        $mine = $this->tenant();
        [$branch, , $seller] = $this->shopFloor();

        $this->assertNotSame($other->id, $mine->id);
        $this->assertNotNull($otherVariant->id);

        $this->actingAs($seller)
            ->getJson("/api/scan?code=SKU-10452&branch_id={$branch->id}")
            ->assertNotFound()
            ->assertJsonPath('code', 'variant_not_found');
    }

    public function test_a_seller_cannot_scan_in_a_branch_that_is_not_theirs(): void
    {
        $this->tenant();
        [, , $seller] = $this->shopFloor();
        [$otherBranch] = $this->shopFloor();

        $variant = ProductVariant::factory()->create();
        $this->giveStock($variant, $otherBranch, 5);

        $this->actingAs($seller)
            ->getJson("/api/scan?code={$variant->sku}&branch_id={$otherBranch->id}")
            ->assertForbidden()
            ->assertJsonPath('code', 'branch_not_allowed');
    }

    protected function tearDown(): void
    {
        app(BusinessContext::class)->forgetBusiness();

        parent::tearDown();
    }
}
