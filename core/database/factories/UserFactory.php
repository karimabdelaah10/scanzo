<?php

namespace Database\Factories;

use App\Enums\Role;
use App\Models\Branch;
use App\Models\Business;
use App\Support\BusinessContext;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<\App\Models\User>
 */
class UserFactory extends Factory
{
    protected static ?string $password = null;

    public function definition(): array
    {
        return [
            'business_id' => app(BusinessContext::class)->businessId() ?? Business::factory(),
            'branch_id' => null,
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->numerify('010########'),
            'password' => static::$password ??= Hash::make('password'),
            'role' => Role::Seller,
            'is_active' => true,
        ];
    }

    public function owner(): static
    {
        return $this->state(fn () => [
            'role' => Role::Owner,
            'branch_id' => null,
        ]);
    }

    public function branchManager(Branch|string|null $branch = null): static
    {
        return $this->state(fn () => [
            'role' => Role::BranchManager,
            'branch_id' => $branch instanceof Branch ? $branch->id : ($branch ?? Branch::factory()),
        ]);
    }

    public function seller(Branch|string|null $branch = null): static
    {
        return $this->state(fn () => [
            'role' => Role::Seller,
            'branch_id' => $branch instanceof Branch ? $branch->id : ($branch ?? Branch::factory()),
        ]);
    }

    public function unverified(): static
    {
        return $this->state(fn () => ['email_verified_at' => null]);
    }
}
