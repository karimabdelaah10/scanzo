<?php

namespace Database\Factories;

use App\Enums\DeviceType;
use App\Models\Branch;
use App\Models\Business;
use App\Support\BusinessContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\Device>
 */
class DeviceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'business_id' => app(BusinessContext::class)->businessId() ?? Business::factory(),
            'branch_id' => Branch::factory(),
            'name' => 'كاشير '.fake()->randomNumber(2),
            'code' => $this->nextCode(),
            'type' => DeviceType::Mobile,
            // In grace by default: a device that never synced cannot sell.
            'last_sync_at' => now(),
            'is_active' => true,
        ];
    }

    /**
     * A counter, not a random draw: two random letter+digit codes collide about
     * once in 260 runs, because there are only 234 of them. "T001" (the column holds 4 characters) cannot
     * clash with the hand-picked codes tests use (A1, B9, ...), which are two
     * characters, nor with each other.
     */
    private static int $codeCounter = 0;

    private function nextCode(): string
    {
        return sprintf('T%03d', ++self::$codeCounter);
    }

    public function neverSynced(): static
    {
        return $this->state(fn () => ['last_sync_at' => null]);
    }

    public function desktop(): static
    {
        return $this->state(fn () => ['type' => DeviceType::Desktop]);
    }
}
