<?php

namespace Database\Factories;

use App\Models\Business;
use App\Models\Product;
use App\Support\BusinessContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\ProductVariant>
 */
class ProductVariantFactory extends Factory
{
    public function definition(): array
    {
        $price = fake()->numberBetween(15, 200) * 10;

        return [
            'business_id' => app(BusinessContext::class)->businessId() ?? Business::factory(),
            'product_id' => Product::factory(),
            'sku' => 'SKU-'.fake()->unique()->numberBetween(10000, 99999),
            'barcode' => (string) fake()->unique()->numberBetween(6200000000000, 6299999999999),
            'size' => fake()->randomElement(['S', 'M', 'L', 'XL', '32', '42']),
            'color' => fake()->randomElement(['أسود', 'أبيض', 'أزرق', 'بني']),
            'price' => $price,
            'cost' => $price * 0.6,
            'is_active' => true,
        ];
    }
}
