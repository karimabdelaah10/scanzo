<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\StockLedger;
use App\Support\BusinessContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogueTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_product_is_created_with_its_variants_and_codes_are_generated(): void
    {
        $this->tenant();
        [$branch, , $seller] = $this->shopFloor();
        $owner = User::factory()->owner()->create();

        $response = $this->actingAs($owner)->postJson('/api/products', [
            'name' => 'قميص كتان رجالي',
            'variants' => [
                ['size' => 'M', 'color' => 'أزرق', 'price' => 450, 'cost' => 260],
                ['size' => 'L', 'color' => 'أزرق', 'price' => 450, 'cost' => 260],
                ['size' => 'L', 'color' => 'أبيض', 'price' => 470, 'cost' => 270],
            ],
        ])->assertCreated();

        $response->assertJsonPath('data.name', 'قميص كتان رجالي')
            ->assertJsonCount(3, 'data.variants');

        $skus = collect($response->json('data.variants'))->pluck('sku');

        // Sequential and nobody typed them.
        $this->assertSame(['SKU-10001', 'SKU-10002', 'SKU-10003'], $skus->all());
        $this->assertSame(3, ProductVariant::count());
        $this->assertSame('L · أبيض', $response->json('data.variants.2.label'));
    }

    public function test_saving_a_product_twice_with_the_same_id_makes_one_product(): void
    {
        $this->tenant();
        [$branch] = $this->shopFloor();
        $owner = User::factory()->owner()->create();

        $payload = [
            'id' => (string) \Illuminate\Support\Str::orderedUuid(),
            'name' => 'قميص كتان رجالي',
            'opening_stock_branch_id' => $branch->id,
            'variants' => [
                ['size' => 'M', 'price' => 450, 'opening_stock' => 5],
                ['size' => 'L', 'price' => 450, 'opening_stock' => 5],
            ],
        ];

        $first = $this->actingAs($owner)->postJson('/api/products', $payload)->assertCreated();
        $second = $this->actingAs($owner)->postJson('/api/products', $payload)->assertCreated();

        $this->assertSame($payload['id'], $first->json('data.id'));
        $this->assertSame($payload['id'], $second->json('data.id'));
        $second->assertJsonCount(2, 'data.variants');

        $this->assertSame(1, Product::count());
        $this->assertSame(2, ProductVariant::count());
        // The retry booked no second delivery.
        $this->assertSame(2, \App\Models\StockMovement::count());
    }

    public function test_codes_keep_counting_from_what_is_already_there(): void
    {
        $this->tenant();
        $owner = User::factory()->owner()->create();

        ProductVariant::factory()->create(['sku' => 'SKU-10044']);

        $this->actingAs($owner)->postJson('/api/products', [
            'name' => 'بنطلون',
            'variants' => [['size' => '32', 'price' => 650]],
        ])
            ->assertCreated()
            ->assertJsonPath('data.variants.0.sku', 'SKU-10045');
    }

    public function test_a_shop_may_use_its_own_codes(): void
    {
        $this->tenant();
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)->postJson('/api/products', [
            'name' => 'تيشيرت',
            'variants' => [
                ['size' => 'M', 'price' => 220, 'sku' => 'TSH-M-WHT', 'barcode' => '6221234567890'],
            ],
        ])
            ->assertCreated()
            ->assertJsonPath('data.variants.0.sku', 'TSH-M-WHT')
            ->assertJsonPath('data.variants.0.barcode', '6221234567890');
    }

    public function test_a_code_already_in_use_is_refused(): void
    {
        $this->tenant();
        $owner = User::factory()->owner()->create();

        ProductVariant::factory()->create(['sku' => 'TSH-M-WHT']);

        $this->actingAs($owner)->postJson('/api/products', [
            'name' => 'تيشيرت',
            'variants' => [['price' => 220, 'sku' => 'TSH-M-WHT']],
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'duplicate_sku');

        // Nothing half-written: the new product rolled back with the variant.
        // Not Product::count() === 0: the fixture's ProductVariant::factory()
        // implicitly creates a product of its own, so the table is never empty.
        $this->assertSame(0, Product::where('name', 'تيشيرت')->count());
    }

    public function test_opening_stock_goes_in_as_a_movement_not_a_number(): void
    {
        $this->tenant();
        [$branch] = $this->shopFloor();
        $owner = User::factory()->owner()->create();

        $response = $this->actingAs($owner)->postJson('/api/products', [
            'name' => 'جاكيت جلد',
            'opening_stock_branch_id' => $branch->id,
            'variants' => [
                ['size' => 'L', 'price' => 1850, 'opening_stock' => 3],
                ['size' => 'XL', 'price' => 1850, 'opening_stock' => 2],
            ],
        ])->assertCreated();

        $ids = collect($response->json('data.variants'))->pluck('id');

        $ledger = app(StockLedger::class);
        $this->assertSame(3, $ledger->balance($ids[0], $branch->id));
        $this->assertSame(2, $ledger->balance($ids[1], $branch->id));
    }

    public function test_opening_stock_without_a_branch_is_refused(): void
    {
        $this->tenant();
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)->postJson('/api/products', [
            'name' => 'جاكيت',
            'variants' => [['price' => 1850, 'opening_stock' => 3]],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('opening_stock_branch_id');
    }

    public function test_a_product_needs_at_least_one_variant(): void
    {
        $this->tenant();
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)->postJson('/api/products', [
            'name' => 'حاجة من غير مقاسات',
            'variants' => [],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('variants');
    }

    public function test_a_seller_cannot_change_the_catalogue(): void
    {
        $this->tenant();
        [, , $seller] = $this->shopFloor();

        $this->actingAs($seller)->postJson('/api/products', [
            'name' => 'منتج',
            'variants' => [['price' => 100]],
        ])
            ->assertForbidden()
            ->assertJsonPath('code', 'catalogue_not_allowed');

        $this->assertSame(0, Product::count());
    }

    public function test_a_seller_never_sees_the_cost(): void
    {
        $this->tenant();
        [$branch, , $seller] = $this->shopFloor();
        $owner = User::factory()->owner()->create();

        $product = Product::factory()->create(['name' => 'قميص']);
        ProductVariant::factory()->create([
            'product_id' => $product->id,
            'price' => 450,
            'cost' => 260,
        ]);

        $this->actingAs($owner)->getJson('/api/products')
            ->assertOk()
            ->assertJsonPath('data.0.variants.0.cost', '260.00');

        $this->actingAs($seller)->getJson('/api/products')
            ->assertOk()
            ->assertJsonMissingPath('data.0.variants.0.cost');
    }

    public function test_the_listing_only_counts_stock_when_a_branch_is_named(): void
    {
        $this->tenant();
        [$branch] = $this->shopFloor();
        $owner = User::factory()->owner()->create();

        $product = Product::factory()->create();
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);
        $this->giveStock($variant, $branch, 7);

        $this->actingAs($owner)->getJson('/api/products')
            ->assertOk()
            ->assertJsonMissingPath('data.0.variants.0.stock');

        $this->actingAs($owner)->getJson('/api/products?branch_id='.$branch->id)
            ->assertOk()
            ->assertJsonPath('data.0.variants.0.stock', 7);
    }

    public function test_search_finds_a_product_by_its_code(): void
    {
        $this->tenant();
        $owner = User::factory()->owner()->create();

        $shirt = Product::factory()->create(['name' => 'قميص']);
        ProductVariant::factory()->create(['product_id' => $shirt->id, 'sku' => 'SKU-55555']);
        $jeans = Product::factory()->create(['name' => 'بنطلون']);
        ProductVariant::factory()->create(['product_id' => $jeans->id, 'sku' => 'SKU-66666']);

        $this->actingAs($owner)->getJson('/api/products?search=SKU-55555')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'قميص');
    }

    public function test_changing_a_price_does_not_touch_an_invoice_already_written(): void
    {
        $this->tenant();
        [$branch, $device, $seller] = $this->shopFloor();
        $owner = User::factory()->owner()->create();

        $variant = ProductVariant::factory()->create(['price' => 450]);
        $this->giveStock($variant, $branch, 5);
        $invoice = $this->sell($seller, $device, [['variant_id' => $variant->id, 'quantity' => 1]]);

        $this->actingAs($owner)->patchJson('/api/variants/'.$variant->id, ['price' => 520])
            ->assertOk()
            ->assertJsonPath('data.price', '520.00');

        // The line keeps what it was sold for.
        $this->assertSame('450.00', (string) $invoice->items()->firstOrFail()->unit_price);
        $this->assertSame('450.00', (string) $invoice->fresh()->total);
    }

    public function test_a_variant_with_pieces_on_the_shelf_cannot_be_deleted(): void
    {
        $this->tenant();
        [$branch] = $this->shopFloor();
        $owner = User::factory()->owner()->create();

        $variant = ProductVariant::factory()->create();
        $this->giveStock($variant, $branch, 4);

        $this->actingAs($owner)->deleteJson('/api/variants/'.$variant->id)
            ->assertStatus(422)
            ->assertJsonPath('code', 'variant_still_in_stock')
            ->assertJsonPath('context.on_hand', 4);

        // Taking it off sale is the right move instead.
        $this->actingAs($owner)->patchJson('/api/variants/'.$variant->id, ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);
    }

    public function test_a_product_from_another_business_is_not_reachable(): void
    {
        $this->tenant();
        $otherProduct = Product::factory()->create(['name' => 'منتج محل تاني']);

        $this->tenant();
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)->getJson('/api/products/'.$otherProduct->id)->assertNotFound();
        $this->actingAs($owner)->getJson('/api/products')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_categories_come_back_as_a_tree(): void
    {
        $this->tenant();
        $owner = User::factory()->owner()->create();

        $men = Category::factory()->create(['name' => 'رجالي']);
        Category::factory()->create(['name' => 'قمصان', 'parent_id' => $men->id]);
        Category::factory()->create(['name' => 'حريمي']);

        $response = $this->actingAs($owner)->getJson('/api/categories')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        // Found by name rather than by position: the order depends on the
        // collation, and that is not what this test is about.
        $men = collect($response->json('data'))->firstWhere('name', 'رجالي');

        $this->assertNotNull($men);
        $this->assertCount(1, $men['children']);
        $this->assertSame('قمصان', $men['children'][0]['name']);
    }

    public function test_a_category_with_products_is_refused_and_kept(): void
    {
        $this->tenant();
        $owner = User::factory()->owner()->create();

        $shirts = Category::factory()->create(['name' => 'قمصان']);
        $product = Product::factory()->create(['category_id' => $shirts->id]);

        $this->actingAs($owner)->deleteJson('/api/categories/'.$shirts->id)
            ->assertStatus(422)
            ->assertJsonPath('code', 'category_has_products');

        $this->assertNotNull(Category::find($shirts->id));
        $this->assertSame($shirts->id, $product->fresh()->category_id);
    }

    public function test_a_category_with_sub_categories_is_refused_and_they_stay_put(): void
    {
        $this->tenant();
        $owner = User::factory()->owner()->create();

        $men = Category::factory()->create(['name' => 'رجالي']);
        $shirts = Category::factory()->create(['name' => 'قمصان', 'parent_id' => $men->id]);

        $this->actingAs($owner)->deleteJson('/api/categories/'.$men->id)
            ->assertStatus(422)
            ->assertJsonPath('code', 'category_has_children');

        $this->assertSame($men->id, $shirts->fresh()->parent_id);
    }

    public function test_an_empty_category_is_deleted(): void
    {
        $this->tenant();
        $owner = User::factory()->owner()->create();

        $empty = Category::factory()->create(['name' => 'فاضي']);

        $this->actingAs($owner)->deleteJson('/api/categories/'.$empty->id)->assertNoContent();

        $this->assertNull(Category::find($empty->id));
    }

    public function test_a_category_cannot_be_its_own_parent(): void
    {
        $this->tenant();
        $owner = User::factory()->owner()->create();

        $men = Category::factory()->create(['name' => 'رجالي']);

        $this->actingAs($owner)->patchJson('/api/categories/'.$men->id, [
            'name' => 'رجالي',
            'parent_id' => $men->id,
        ])->assertStatus(422)->assertJsonValidationErrors('parent_id');

        $this->assertNull($men->fresh()->parent_id);
    }

    public function test_a_category_cannot_be_moved_under_its_own_descendant(): void
    {
        $this->tenant();
        $owner = User::factory()->owner()->create();

        $men = Category::factory()->create(['name' => 'رجالي']);
        $shirts = Category::factory()->create(['name' => 'قمصان', 'parent_id' => $men->id]);
        $linen = Category::factory()->create(['name' => 'كتان', 'parent_id' => $shirts->id]);

        foreach ([$shirts, $linen] as $below) {
            $this->actingAs($owner)->patchJson('/api/categories/'.$men->id, [
                'name' => 'رجالي',
                'parent_id' => $below->id,
            ])->assertStatus(422)->assertJsonValidationErrors('parent_id');
        }

        $this->assertNull($men->fresh()->parent_id);
    }

    public function test_a_category_can_still_move_to_an_unrelated_parent(): void
    {
        $this->tenant();
        $owner = User::factory()->owner()->create();

        $men = Category::factory()->create(['name' => 'رجالي']);
        $women = Category::factory()->create(['name' => 'حريمي']);
        $shirts = Category::factory()->create(['name' => 'قمصان', 'parent_id' => $men->id]);

        $this->actingAs($owner)->patchJson('/api/categories/'.$shirts->id, [
            'name' => 'قمصان',
            'parent_id' => $women->id,
        ])->assertOk();

        $this->assertSame($women->id, $shirts->fresh()->parent_id);
    }

    public function test_a_category_holding_only_archived_products_is_refused_and_says_so(): void
    {
        $this->tenant();
        $owner = User::factory()->owner()->create();

        $shirts = Category::factory()->create(['name' => 'قمصان']);
        $product = Product::factory()->create(['category_id' => $shirts->id]);
        $product->delete();

        $this->actingAs($owner)->deleteJson('/api/categories/'.$shirts->id)
            ->assertStatus(422)
            ->assertJsonPath('code', 'category_has_products')
            ->assertJsonPath('context.archived', 1)
            ->assertJsonPath('context.products', 1);

        $this->assertNotNull(Category::find($shirts->id));
        $this->assertSame($shirts->id, Product::withTrashed()->find($product->id)->category_id);
    }

    protected function tearDown(): void
    {
        app(BusinessContext::class)->forgetBusiness();

        parent::tearDown();
    }
}
