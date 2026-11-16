<?php

namespace Database\Factories;

use App\Enums\SubscriptionStatus;
use App\Models\Business;
use App\Support\BusinessContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\Subscription>
 */
class SubscriptionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'business_id' => app(BusinessContext::class)->businessId() ?? Business::factory(),
            'plan' => 'trial',
            'status' => SubscriptionStatus::Active,
            'starts_at' => now(),
            'ends_at' => now()->addDays(14),
            'offline_grace_days' => 7,
        ];
    }
}
