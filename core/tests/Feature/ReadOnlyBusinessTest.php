<?php

namespace Tests\Feature;

use App\Actions\Selling\CreateInvoice;
use App\Enums\BusinessStatus;
use App\Enums\SubscriptionStatus;
use App\Exceptions\DomainException;
use App\Models\Admin;
use App\Models\Business;
use App\Models\ProductVariant;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Two different things that look alike and must stay apart:
 *
 *  - Suspended (a decision of ours, from the platform panel): the shop is read
 *    only. Reads pass, every write is refused, signing out still works.
 *  - Subscription ended (a date passing): shown to the owner, never enforced.
 *    The shop keeps selling. Karim decided that; a shop cannot be locked out
 *    mid-day because a transfer was late.
 */
class ReadOnlyBusinessTest extends TestCase
{
    use RefreshDatabase;

    // ── Suspended: read only ────────────────────────────────────────────

    public function test_a_suspended_business_can_still_read_its_data(): void
    {
        $this->tenant(['status' => BusinessStatus::Suspended]);
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)->getJson('/api/products')->assertOk();
        $this->actingAs($owner)->getJson('/api/branches')->assertOk();
        $this->actingAs($owner)->getJson('/api/settings')->assertOk();
        $this->actingAs($owner)->call('HEAD', '/api/products')->assertOk();

        $this->actingAs($owner)->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('business.status', 'suspended')
            ->assertJsonPath('business.read_only', true);
    }

    #[DataProvider('writes')]
    public function test_a_suspended_business_cannot_write(string $method, string $uri, array $body): void
    {
        $this->tenant(['status' => BusinessStatus::Suspended]);
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)->json($method, $uri, $body)
            ->assertForbidden()
            ->assertJsonPath('code', 'business_suspended')
            ->assertJsonStructure(['message', 'code', 'context']);
    }

    public static function writes(): array
    {
        $id = '00000000-0000-0000-0000-000000000000';

        return [
            'create a category' => ['POST', '/api/categories', ['name' => 'Shirts']],
            'create a product' => ['POST', '/api/products', []],
            'edit a product' => ['PATCH', "/api/products/{$id}", ['name' => 'x']],
            'delete a category' => ['DELETE', "/api/categories/{$id}", []],
            'receive stock' => ['POST', '/api/stock/receive', []],
            'adjust stock' => ['POST', '/api/stock/adjust', []],
            'add a user' => ['POST', '/api/users', []],
            'add a branch' => ['POST', '/api/branches', []],
            'change settings' => ['PATCH', '/api/settings', []],
            'sell' => ['POST', '/api/invoices', []],
            'return' => ['POST', '/api/returns', []],
        ];
    }

    public function test_a_suspended_user_can_still_sign_out(): void
    {
        $this->tenant(['status' => BusinessStatus::Suspended]);
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)->postJson('/api/logout')->assertOk();
    }

    public function test_the_action_itself_still_refuses_a_suspended_business(): void
    {
        // The second line of defence: a caller that never goes through HTTP.
        $this->tenant(['status' => BusinessStatus::Suspended]);
        [$branch, $device, $seller] = $this->shopFloor();

        $variant = ProductVariant::factory()->create();
        $this->giveStock($variant, $branch, 5);

        $this->expectException(DomainException::class);

        app(CreateInvoice::class)->handle(
            seller: $seller,
            device: $device,
            items: [['variant_id' => $variant->id, 'quantity' => 1]],
        );
    }

    public function test_an_active_business_is_not_affected(): void
    {
        $this->tenant();
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)->postJson('/api/categories', ['name' => 'Shirts'])->assertCreated();
        $this->actingAs($owner)->getJson('/api/me')->assertJsonPath('business.read_only', false);
    }

    // ── Subscription ended: shown, never enforced ───────────────────────

    public function test_a_shop_whose_subscription_ended_still_sells(): void
    {
        // Deliberate. If this fails, someone made an ended subscription stop
        // the shop: read docs/decisions.md and ask Karim before changing it.
        $this->tenant();
        Subscription::factory()->create([
            'status' => SubscriptionStatus::Expired,
            'ends_at' => now()->subDays(3),
        ]);
        [$branch, $device, $seller] = $this->shopFloor();

        $variant = ProductVariant::factory()->create(['price' => 100]);
        $this->giveStock($variant, $branch, 5);

        $this->actingAs($seller)->postJson('/api/invoices', [
            'device_id' => $device->id,
            'items' => [['variant_id' => $variant->id, 'quantity' => 1]],
        ])->assertCreated();
    }

    public function test_me_reports_an_active_subscription(): void
    {
        $this->tenant();
        Subscription::factory()->create(['ends_at' => now()->addDays(30)]);
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('subscription.state', 'active')
            ->assertJsonPath('subscription.days_left', 30);
    }

    public function test_me_reports_a_subscription_that_is_about_to_end(): void
    {
        $this->tenant();
        Subscription::factory()->create(['ends_at' => now()->addDays(3)]);
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('subscription.state', 'expiring')
            ->assertJsonPath('subscription.days_left', 3);
    }

    public function test_the_last_day_still_counts_as_ending_not_ended(): void
    {
        $this->tenant();
        Subscription::factory()->create(['ends_at' => now()->endOfDay()]);
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)->getJson('/api/me')
            ->assertJsonPath('subscription.state', 'expiring')
            ->assertJsonPath('subscription.days_left', 0);
    }

    public function test_me_reports_an_ended_subscription(): void
    {
        $this->tenant();
        Subscription::factory()->create(['ends_at' => now()->subDay()]);
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('subscription.state', 'expired')
            ->assertJsonPath('subscription.days_left', null);
    }

    public function test_a_cancelled_subscription_counts_as_ended_even_before_its_date(): void
    {
        $this->tenant();
        Subscription::factory()->create([
            'status' => SubscriptionStatus::Cancelled,
            'ends_at' => now()->addDays(20),
        ]);
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)->getJson('/api/me')
            ->assertJsonPath('subscription.state', 'expired');
    }

    // ── Platform panel: who to call ─────────────────────────────────────

    public function test_the_panel_filters_ended_and_ending_subscriptions(): void
    {
        $this->subscribed('Ended Shop', ['status' => SubscriptionStatus::Expired, 'ends_at' => now()->subDays(2)]);
        $this->subscribed('Ending Shop', ['ends_at' => now()->addDays(2)]);
        $this->subscribed('Healthy Shop', ['ends_at' => now()->addDays(60)]);

        $admin = Admin::factory()->create();
        Auth::guard('admin')->setUser($admin);
        Auth::shouldUse('web');

        $this->get('/admin/businesses?subscription=expired')
            ->assertOk()
            ->assertSee('Ended Shop')
            ->assertDontSee('Ending Shop')
            ->assertDontSee('Healthy Shop');

        $this->get('/admin/businesses?subscription=expiring')
            ->assertOk()
            ->assertSee('Ending Shop')
            ->assertDontSee('Ended Shop')
            ->assertDontSee('Healthy Shop');

        $this->get('/admin/businesses')
            ->assertOk()
            ->assertSee('Ended Shop')
            ->assertSee('Ending Shop')
            ->assertSee('Healthy Shop');
    }

    private function subscribed(string $name, array $subscription): Business
    {
        $business = Business::factory()->create(['name' => $name]);

        Subscription::factory()->create($subscription + ['business_id' => $business->id]);

        return $business;
    }
}
