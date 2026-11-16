<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Support\BusinessContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VariantSearchTest extends TestCase
{
    use RefreshDatabase;

    private function variant(string $name, string $size, string $color, array $extra = []): ProductVariant
    {
        $product = Product::factory()->create(['name' => $name]);

        return ProductVariant::factory()->create([
            'product_id' => $product->id,
            'size' => $size,
            'color' => $color,
            ...$extra,
        ]);
    }

    public function test_it_matches_name_size_or_colour_and_carries_what_the_cart_needs(): void
    {
        $this->tenant();
        [$branch, , $seller] = $this->shopFloor();

        $shirt = $this->variant('قميص كتان', 'L', 'أزرق', ['price' => 450, 'sku' => 'SKU-10001']);
        $this->variant('بنطلون جينز', 'M', 'أسود');
        $this->giveStock($shirt, $branch, 6);

        $row = $this->actingAs($seller)
            ->getJson("/api/variants/search?q=قميص&branch_id={$branch->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $shirt->id)
            ->assertJsonPath('data.0.product_name', 'قميص كتان')
            ->assertJsonPath('data.0.label', 'L · أزرق')
            ->assertJsonPath('data.0.sku', 'SKU-10001')
            ->assertJsonPath('data.0.price', '450.00')
            ->assertJsonPath('data.0.available', 6)
            ->assertJsonPath('meta.has_more', false)
            ->json('data.0');

        // The seller must never see the margin.
        $this->assertArrayNotHasKey('cost', $row);

        // By colour, and by size.
        $this->actingAs($seller)->getJson("/api/variants/search?q=أسود&branch_id={$branch->id}")
            ->assertJsonCount(1, 'data');
        $this->actingAs($seller)->getJson("/api/variants/search?q=L&branch_id={$branch->id}")
            ->assertUnprocessable(); // a single character is refused
    }

    public function test_every_word_has_to_match_so_more_words_narrow_the_result(): void
    {
        $this->tenant();
        [$branch, , $seller] = $this->shopFloor();

        $blue = $this->variant('قميص كتان', 'L', 'أزرق');
        $this->variant('قميص كتان', 'L', 'أبيض');

        $this->actingAs($seller)->getJson('/api/variants/search?'.http_build_query(['q' => 'قميص', 'branch_id' => $branch->id]))
            ->assertJsonCount(2, 'data');

        $this->actingAs($seller)->getJson('/api/variants/search?'.http_build_query(['q' => 'قميص أزرق', 'branch_id' => $branch->id]))
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $blue->id);
    }

    public function test_it_returns_active_items_only(): void
    {
        $this->tenant();
        [$branch, , $seller] = $this->shopFloor();

        $live = $this->variant('تيشيرت', 'M', 'أحمر');
        $this->variant('تيشيرت', 'L', 'أحمر', ['is_active' => false]);

        $hidden = Product::factory()->create(['name' => 'تيشيرت مخفي', 'is_active' => false]);
        ProductVariant::factory()->create(['product_id' => $hidden->id]);

        $deleted = $this->variant('تيشيرت محذوف', 'S', 'أحمر');
        $deleted->delete();

        $result = $this->actingAs($seller)
            ->getJson('/api/variants/search?'.http_build_query(['q' => 'تيشيرت', 'branch_id' => $branch->id]))
            ->assertOk();

        $this->assertSame([$live->id], collect($result->json('data'))->pluck('id')->all());
    }

    public function test_it_never_returns_another_shops_items(): void
    {
        $this->tenant();
        [$branch, , $seller] = $this->shopFloor();
        $mine = $this->variant('جاكيت', 'L', 'بني');

        // A second shop with an item of the same name.
        $this->tenant();
        $this->variant('جاكيت', 'L', 'بني');

        // Back to the first shop.
        app(BusinessContext::class)->setBusiness($seller->business);

        $result = $this->actingAs($seller)
            ->getJson('/api/variants/search?'.http_build_query(['q' => 'جاكيت', 'branch_id' => $branch->id]))
            ->assertOk();

        $this->assertSame([$mine->id], collect($result->json('data'))->pluck('id')->all());
    }

    public function test_another_shops_branch_is_refused(): void
    {
        $this->tenant();
        [, , $seller] = $this->shopFloor();

        $this->tenant();
        [$foreignBranch] = $this->shopFloor();

        $this->actingAs($seller)
            ->getJson('/api/variants/search?'.http_build_query(['q' => 'جاكيت', 'branch_id' => $foreignBranch->id]))
            ->assertUnprocessable();
    }

    public function test_a_seller_cannot_search_for_another_branch_in_the_same_shop(): void
    {
        $this->tenant();
        [, , $seller] = $this->shopFloor();
        [$otherBranch] = $this->shopFloor();

        $this->actingAs($seller)
            ->getJson('/api/variants/search?'.http_build_query(['q' => 'جاكيت', 'branch_id' => $otherBranch->id]))
            ->assertForbidden();
    }

    public function test_results_come_in_pages_and_percent_signs_are_not_wildcards(): void
    {
        $this->tenant();
        [$branch, , $seller] = $this->shopFloor();

        $product = Product::factory()->create(['name' => 'كاب']);
        ProductVariant::factory()->count(25)->create(['product_id' => $product->id]);

        $first = $this->actingAs($seller)
            ->getJson('/api/variants/search?'.http_build_query(['q' => 'كاب', 'branch_id' => $branch->id]))
            ->assertJsonCount(20, 'data')
            ->assertJsonPath('meta.has_more', true);

        $second = $this->actingAs($seller)
            ->getJson('/api/variants/search?'.http_build_query(['q' => 'كاب', 'branch_id' => $branch->id, 'page' => 2]))
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('meta.has_more', false);

        // No id on page two was already on page one.
        $this->assertEmpty(array_intersect(
            collect($first->json('data'))->pluck('id')->all(),
            collect($second->json('data'))->pluck('id')->all(),
        ));

        $this->actingAs($seller)
            ->getJson('/api/variants/search?'.http_build_query(['q' => '%%', 'branch_id' => $branch->id]))
            ->assertJsonCount(0, 'data');
    }
}
