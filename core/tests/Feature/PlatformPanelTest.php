<?php

namespace Tests\Feature;

use App\Enums\BusinessStatus;
use App\Enums\Role;
use App\Models\Admin;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Device;
use App\Models\ProductVariant;
use App\Models\Subscription;
use App\Models\User;
use App\Support\BusinessContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PlatformPanelTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Sign an admin in on the admin guard without making it the default guard.
     *
     * actingAs($admin, 'admin') would also call shouldUse('admin'), and then the
     * shops' `auth` middleware would resolve the admin on /api — hiding exactly
     * the leak these tests are meant to catch. A real request never changes the
     * default guard, so neither does this.
     */
    private function signInAsAdmin(?Admin $admin = null): Admin
    {
        $admin ??= Admin::factory()->create();

        Auth::guard('admin')->setUser($admin);
        Auth::shouldUse('web');

        return $admin;
    }

    // ── Sign-in ─────────────────────────────────────────────────────────

    public function test_an_admin_can_sign_in_and_gets_their_own_session_cookie(): void
    {
        $admin = Admin::factory()->create();

        $this->post('/admin/login', ['email' => $admin->email, 'password' => 'password'])
            ->assertRedirect(route('admin.businesses.index'))
            ->assertCookie('scanzo_admin_session');

        $this->assertAuthenticatedAs($admin, 'admin');
    }

    public function test_a_wrong_password_does_not_sign_in(): void
    {
        $admin = Admin::factory()->create();

        $this->from('/admin/login')
            ->post('/admin/login', ['email' => $admin->email, 'password' => 'nope'])
            ->assertSessionHasErrors('email');

        $this->assertGuest('admin');
    }

    public function test_a_switched_off_admin_cannot_sign_in(): void
    {
        $admin = Admin::factory()->inactive()->create();

        $this->post('/admin/login', ['email' => $admin->email, 'password' => 'password'])
            ->assertSessionHasErrors('email');

        $this->assertGuest('admin');
    }

    public function test_an_admin_switched_off_while_signed_in_is_refused_on_the_next_request(): void
    {
        $admin = $this->signInAsAdmin();

        $this->get('/admin/businesses')->assertOk();

        $admin->update(['is_active' => false]);

        $this->get('/admin/businesses')->assertRedirect(route('admin.login'));
        $this->assertGuest('admin');
    }

    public function test_signed_out_visitors_are_sent_to_the_admin_sign_in(): void
    {
        $this->get('/admin/businesses')->assertRedirect(route('admin.login'));
    }

    // ── Two separate worlds ─────────────────────────────────────────────

    public function test_a_shop_owner_cannot_open_the_panel(): void
    {
        $this->tenant();
        $owner = User::factory()->owner()->create();

        // 'web' is explicit: after a request to /admin the default guard is
        // left as `admin` in this process, and actingAs(null) would follow it.
        $this->actingAs($owner, 'web')->get('/admin/businesses')
            ->assertRedirect(route('admin.login'));

        $this->actingAs($owner, 'web')->get('/admin/businesses/create')
            ->assertRedirect(route('admin.login'));
    }

    public function test_an_admin_cannot_use_the_shops_api(): void
    {
        $this->signInAsAdmin();

        $this->getJson('/api/me')->assertUnauthorized();
        $this->getJson('/api/invoices')->assertUnauthorized();
    }

    // ── Reading across tenants ──────────────────────────────────────────

    public function test_the_list_shows_every_business_with_its_own_numbers_even_with_a_stale_tenant_set(): void
    {
        $a = $this->tenant(['name' => 'محل ألف']);
        [$branchA, $deviceA, $sellerA] = $this->shopFloor();
        Branch::factory()->create();
        User::factory()->owner()->create(['is_active' => false]);
        Subscription::factory()->create();
        $variant = ProductVariant::factory()->create(['price' => 100]);
        $this->giveStock($variant, $branchA, 5);
        $this->sell($sellerA, $deviceA, [['variant_id' => $variant->id, 'quantity' => 1]]);

        $b = $this->tenant(['name' => 'محل باء']);
        $this->shopFloor();

        // The tenant left set is B, the last one touched. A panel that leaned
        // on "no tenant" would now show B only.
        $this->assertTrue(app(BusinessContext::class)->hasBusiness());

        $this->signInAsAdmin();
        $response = $this->get('/admin/businesses')->assertOk()
            ->assertSee('محل ألف')->assertSee('محل باء');

        $rows = $response->viewData('businesses')->getCollection()->keyBy('id');

        $this->assertSame(2, $rows[$a->id]->branches_count);
        // The inactive owner does not count; the seller does.
        $this->assertSame(1, $rows[$a->id]->users_count);
        $this->assertSame(1, $rows[$a->id]->devices_count);
        $this->assertNotNull($rows[$a->id]->last_invoice_at);

        $this->assertSame(1, $rows[$b->id]->branches_count);
        $this->assertNull($rows[$b->id]->last_invoice_at);

        // And the panel leaves no tenant behind.
        $this->assertFalse(app(BusinessContext::class)->hasBusiness());
    }

    public function test_the_list_filters_by_status_and_searches_by_name(): void
    {
        $this->tenant(['name' => 'ملابس النور', 'status' => BusinessStatus::Active]);
        $this->tenant(['name' => 'ملابس الأمل', 'status' => BusinessStatus::Suspended]);

        $this->signInAsAdmin();

        $this->get('/admin/businesses?status=suspended')
            ->assertSee('ملابس الأمل')->assertDontSee('ملابس النور');

        $this->get('/admin/businesses?q='.urlencode('النور'))
            ->assertSee('ملابس النور')->assertDontSee('ملابس الأمل');
    }

    // ── Onboarding ──────────────────────────────────────────────────────

    public function test_creating_a_business_does_all_five_things_and_shows_the_password_once(): void
    {
        $this->signInAsAdmin();

        $response = $this->post('/admin/businesses', [
            'business_name' => 'ملابس السعادة',
            'phone' => '0100000000',
            'owner_name' => 'أحمد',
            'owner_email' => 'Ahmed@Saada.local',
            'plan' => 'trial',
            'trial_days' => 14,
        ])->assertOk();

        $response->assertHeader('Cache-Control');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));

        $password = $response->viewData('password');
        $response->assertSee($password);

        // The five things, read with no tenant set — the way the panel sees them.
        $business = Business::where('name', 'ملابس السعادة')->firstOrFail();
        $this->assertSame(BusinessStatus::Trial, $business->status);

        $this->assertSame(1, Subscription::where('business_id', $business->id)->count());
        $this->assertSame(1, Branch::where('business_id', $business->id)->count());
        $this->assertSame(1, Device::where('business_id', $business->id)->count());

        $owner = User::where('business_id', $business->id)->firstOrFail();
        $this->assertSame(Role::Owner, $owner->role);
        $this->assertSame('ahmed@saada.local', $owner->email);

        // What was displayed is what works. The default guard is put back first:
        // the request above left it as `admin`, which a real shop request never sees.
        Auth::shouldUse('web');
        $this->assertTrue(Hash::check($password, $owner->password));
        $this->postJson('/api/login', ['email' => 'ahmed@saada.local', 'password' => $password])->assertOk();
    }

    public function test_resubmitting_the_same_owner_email_is_refused_and_creates_nothing(): void
    {
        $this->signInAsAdmin();

        $form = [
            'business_name' => 'محل أول', 'owner_name' => 'س', 'owner_email' => 'dup@shop.local',
            'plan' => 'trial', 'trial_days' => 14,
        ];

        $this->post('/admin/businesses', $form)->assertOk();
        $this->post('/admin/businesses', $form)->assertSessionHasErrors('owner_email');

        $this->assertSame(1, Business::count());
    }

    // ── Subscription and status ─────────────────────────────────────────

    public function test_the_subscription_can_be_edited_and_empty_limits_mean_unlimited(): void
    {
        $business = $this->tenant();
        $sub = Subscription::factory()->create(['max_users' => 3]);
        app(BusinessContext::class)->forgetBusiness();

        $this->signInAsAdmin();

        $this->put("/admin/businesses/{$business->id}/subscription", [
            'plan' => 'pro',
            'status' => 'active',
            'starts_at' => '2026-01-01',
            'ends_at' => '2026-12-31',
            'offline_grace_days' => 10,
            'max_branches' => 4,
            'max_users' => '',
            'max_devices' => 6,
            'note' => 'دفع كاش',
        ])->assertRedirect(route('admin.businesses.show', $business));

        $sub->refresh();
        $this->assertSame('pro', $sub->plan);
        $this->assertSame(10, $sub->offline_grace_days);
        $this->assertSame(4, (int) $sub->max_branches);
        $this->assertNull($sub->max_users);
        $this->assertSame('2026-12-31 23:59:59', $sub->ends_at->format('Y-m-d H:i:s'));
        $this->assertSame('دفع كاش', $sub->note);
    }

    public function test_the_show_page_reports_usage_against_the_limits_for_that_business_only(): void
    {
        $business = $this->tenant();
        $this->shopFloor();
        Subscription::factory()->create(['max_branches' => 3, 'max_users' => null]);

        // Another shop with more of everything must not leak into the numbers.
        $this->tenant();
        $this->shopFloor();
        $this->shopFloor();

        $this->signInAsAdmin();
        $usage = $this->get("/admin/businesses/{$business->id}")->assertOk()->viewData('usage');

        $this->assertSame(['used' => 1, 'limit' => 3], $usage['branches']);
        $this->assertSame(['used' => 1, 'limit' => null], $usage['users']);
        $this->assertSame(['used' => 1, 'limit' => null], $usage['devices']);
    }

    public function test_suspending_from_the_panel_really_stops_selling(): void
    {
        $business = $this->tenant();
        [$branch, $device, $seller] = $this->shopFloor();
        $variant = ProductVariant::factory()->create(['price' => 100]);
        $this->giveStock($variant, $branch, 5);
        app(BusinessContext::class)->forgetBusiness();

        $sale = ['device_id' => $device->id, 'items' => [['variant_id' => $variant->id, 'quantity' => 1]]];

        $this->actingAs($seller->fresh(), 'web')->postJson('/api/invoices', $sale)->assertCreated();

        $this->signInAsAdmin();
        $this->put("/admin/businesses/{$business->id}/status", ['status' => 'suspended'])
            ->assertRedirect(route('admin.businesses.show', $business));

        $this->assertSame(BusinessStatus::Suspended, $business->fresh()->status);

        $this->actingAs($seller->fresh(), 'web')->postJson('/api/invoices', $sale)
            ->assertForbidden()
            ->assertJsonPath('code', 'business_suspended');

        // Turned back on, it sells again.
        $this->signInAsAdmin();
        $this->put("/admin/businesses/{$business->id}/status", ['status' => 'active']);

        $this->actingAs($seller->fresh(), 'web')->postJson('/api/invoices', $sale)->assertCreated();
    }
}
