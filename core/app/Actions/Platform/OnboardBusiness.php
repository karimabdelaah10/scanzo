<?php

namespace App\Actions\Platform;

use App\Enums\BusinessStatus;
use App\Enums\DeviceType;
use App\Enums\Role;
use App\Enums\SubscriptionStatus;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Device;
use App\Models\Subscription;
use App\Models\User;
use App\Support\BusinessContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Everything a new subscriber needs to start selling, in one transaction:
 * the business, its subscription, a first branch, the owner's account and a
 * first till.
 *
 * This is what taking on a customer actually is — there is no software to
 * install at the shop. The platform panel and the onboarding command both call
 * this, so the two can never drift apart.
 */
class OnboardBusiness
{
    /**
     * @return array{business: Business, branch: Branch, owner: User, device: Device, subscription: Subscription}
     */
    public function handle(
        string $businessName,
        string $ownerName,
        string $ownerEmail,
        string $ownerPassword,
        ?string $slug = null,
        ?string $phone = null,
        string $branchName = 'الفرع الرئيسي',
        string $branchCode = 'MAIN',
        string $plan = 'trial',
        int $trialDays = 14,
        string $deviceName = 'موبايل البائع',
        ?string $deviceCode = null,
        DeviceType $deviceType = DeviceType::Mobile,
        ?array $settings = null,
    ): array {
        return DB::transaction(function () use (
            $businessName, $ownerName, $ownerEmail, $ownerPassword, $slug, $phone,
            $branchName, $branchCode, $plan, $trialDays,
            $deviceName, $deviceCode, $deviceType, $settings
        ) {
            $business = Business::create([
                'name' => $businessName,
                'slug' => $slug ?? $this->uniqueSlug($businessName),
                'status' => BusinessStatus::Trial,
                'phone' => $phone,
                // A copy, so changing the defaults later never alters a shop
                // that is already running.
                'settings' => $settings ?? config('scanzo.business_defaults'),
            ]);

            // From here every create picks up business_id by itself.
            $previous = app(BusinessContext::class)->businessId();
            app(BusinessContext::class)->setBusiness($business);

            try {
                $subscription = Subscription::create([
                    'plan' => $plan,
                    'status' => SubscriptionStatus::Active,
                    'starts_at' => now(),
                    'ends_at' => now()->addDays($trialDays),
                    'offline_grace_days' => config('scanzo.offline_grace_days'),
                ]);

                $branch = Branch::create([
                    'name' => $branchName,
                    'code' => strtoupper($branchCode),
                    'is_active' => true,
                ]);

                // No branch_id: an owner works across all of them.
                $owner = User::create([
                    'name' => $ownerName,
                    'email' => strtolower(trim($ownerEmail)),
                    'password' => $ownerPassword,
                    'role' => Role::Owner,
                    'is_active' => true,
                ]);

                $device = Device::create([
                    'branch_id' => $branch->id,
                    'name' => $deviceName,
                    'code' => strtoupper($deviceCode ?? 'A1'),
                    'type' => $deviceType,
                    // A device that has never reached the server cannot sell,
                    // so the first till starts in grace.
                    'last_sync_at' => now(),
                    'is_active' => true,
                ]);
            } finally {
                app(BusinessContext::class)->setBusinessId($previous);
            }

            return compact('business', 'branch', 'owner', 'device', 'subscription');
        });
    }

    protected function uniqueSlug(string $name): string
    {
        // Str::slug drops anything it cannot transliterate, so a name written
        // only in Arabic can come back empty or down to a letter or two. The
        // slug has to survive being put in a URL, so fall back rather than
        // produce something unusable.
        $base = Str::slug($name);

        if (strlen($base) < 3) {
            $base = 'shop';
        }

        $slug = $base;
        $suffix = 2;

        while (Business::where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }
}
