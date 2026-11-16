<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Device;
use App\Models\User;
use App\Support\BusinessContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * An OPEN shift by default, with its open_device_id set the way OpenShift does.
 *
 * @extends Factory<\App\Models\Shift>
 */
class ShiftFactory extends Factory
{
    public function definition(): array
    {
        return [
            'business_id' => app(BusinessContext::class)->businessId() ?? Business::factory(),
            'branch_id' => Branch::factory(),
            'device_id' => Device::factory(),
            'user_id' => User::factory(),
            'opening_float' => '100.00',
            'opened_at' => now(),
        ];
    }

    /** Sets open_device_id after the device is known. */
    public function configure(): static
    {
        return $this->afterMaking(function ($shift) {
            if ($shift->closed_at === null && $shift->open_device_id === null) {
                $shift->open_device_id = $shift->device_id;
            }
        });
    }
}
