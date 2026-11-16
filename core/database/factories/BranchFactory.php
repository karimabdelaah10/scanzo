<?php

namespace Database\Factories;

use App\Models\Business;
use App\Support\BusinessContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\Branch>
 *
 * business_id comes from the current BusinessContext when one is set, which is
 * what a test should do — set the context once, then every factory lines up
 * under the same tenant. With no context each factory makes its own business.
 */
class BranchFactory extends Factory
{
    public function definition(): array
    {
        return [
            'business_id' => app(BusinessContext::class)->businessId() ?? Business::factory(),
            'name' => 'فرع '.fake()->city(),
            'code' => strtoupper(fake()->unique()->bothify('??#')),
            'address' => fake()->address(),
            'phone' => fake()->numerify('03######'),
            'is_active' => true,
        ];
    }
}
