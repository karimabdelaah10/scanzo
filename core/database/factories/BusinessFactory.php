<?php

namespace Database\Factories;

use App\Enums\BusinessStatus;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<\App\Models\Business>
 */
class BusinessFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1000, 9999),
            'status' => BusinessStatus::Active,
            'phone' => fake()->numerify('010########'),
            'settings' => [
                'currency' => 'EGP',
                'returns' => ['window_days' => 14],
            ],
        ];
    }

    public function suspended(): static
    {
        return $this->state(fn () => ['status' => BusinessStatus::Suspended]);
    }

    public function trial(): static
    {
        return $this->state(fn () => ['status' => BusinessStatus::Trial]);
    }
}
