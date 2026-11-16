<?php

namespace Database\Factories;

use App\Models\Business;
use App\Support\BusinessContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\Product>
 */
class ProductFactory extends Factory
{
    public function definition(): array
    {
        return [
            'business_id' => app(BusinessContext::class)->businessId() ?? Business::factory(),
            'category_id' => null,
            'name' => fake()->randomElement(['قميص', 'بنطلون', 'تيشيرت', 'جاكيت', 'فستان']).' '.fake()->word(),
            'description' => null,
            'is_active' => true,
        ];
    }
}
