<?php

namespace Tests\Feature;

use App\Actions\Platform\OnboardBusiness;
use App\Enums\BusinessStatus;
use App\Enums\Role;
use App\Models\Business;
use App\Models\ProductVariant;
use App\Models\User;
use App\Support\BusinessContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OnboardBusinessTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_new_subscriber_can_sign_in_and_sell_straight_away(): void
    {
        // Nothing is set up: this is the state of a fresh install.
        app(BusinessContext::class)->forgetBusiness();

        $result = app(OnboardBusiness::class)->handle(
            businessName: 'ملابس السعادة',
            ownerName: 'أحمد',
            ownerEmail: 'ahmed@saada.local',
            ownerPassword: 'password',
        );

        $this->assertSame(BusinessStatus::Trial, $result['business']->status);
        // Whatever Str::slug makes of an Arabic name, it has to be usable in a URL.
        $this->assertMatchesRegularExpression('/^[a-z0-9-]{3,}$/', $result['business']->slug);
        $this->assertSame(Role::Owner, $result['owner']->role);
        $this->assertNull($result['owner']->branch_id);
        $this->assertSame('MAIN', $result['branch']->code);
        $this->assertSame('A1', $result['device']->code);
        $this->assertTrue($result['subscription']->isActive());

        // The owner signs in with no tenant set anywhere, the way a real first
        // login works.
        app(BusinessContext::class)->forgetBusiness();

        $this->postJson('/api/login', [
            'email' => 'ahmed@saada.local',
            'password' => 'password',
        ])
            ->assertOk()
            ->assertJsonPath('business.name', 'ملابس السعادة')
            ->assertJsonPath('user.role', 'owner')
            ->assertJsonCount(1, 'branches')
            ->assertJsonCount(1, 'devices');

        // And can put the first product in and sell it.
        $owner = $result['owner'];

        $product = $this->actingAs($owner)->postJson('/api/products', [
            'name' => 'قميص',
            'opening_stock_branch_id' => $result['branch']->id,
            'variants' => [['size' => 'L', 'price' => 300, 'opening_stock' => 5]],
        ])->assertCreated();

        $variantId = $product->json('data.variants.0.id');

        $this->actingAs($owner)->postJson('/api/invoices', [
            'device_id' => $result['device']->id,
            'items' => [['variant_id' => $variantId, 'quantity' => 2]],
        ])
            ->assertCreated()
            ->assertJsonPath('data.number', 'A1-000001')
            ->assertJsonPath('data.total', '600.00');

        $this->actingAs($owner)
            ->getJson('/api/stock?branch_id='.$result['branch']->id)
            ->assertOk()
            ->assertJsonPath('data.0.quantity', 3);
    }

    public function test_the_first_till_can_sell_without_syncing_first(): void
    {
        // A till that has never reached the server is refused, so onboarding
        // has to start it inside the grace period or the shop cannot open.
        app(BusinessContext::class)->forgetBusiness();

        $result = app(OnboardBusiness::class)->handle(
            businessName: 'محل',
            ownerName: 'مالك',
            ownerEmail: 'owner@shop.local',
            ownerPassword: 'password',
        );

        $this->assertNotNull($result['device']->last_sync_at);
        $this->assertTrue($result['device']->isWithinOfflineGrace(7));
    }

    public function test_two_shops_with_the_same_name_get_different_slugs(): void
    {
        app(BusinessContext::class)->forgetBusiness();

        $action = app(OnboardBusiness::class);

        $first = $action->handle('ملابس النور', 'أ', 'a@one.local', 'password');
        $second = $action->handle('ملابس النور', 'ب', 'b@two.local', 'password');

        $this->assertNotSame($first['business']->slug, $second['business']->slug);
        $this->assertSame(2, Business::count());
    }

    public function test_onboarding_leaves_nothing_behind_if_it_fails(): void
    {
        app(BusinessContext::class)->forgetBusiness();

        $existing = $this->tenant();
        User::factory()->create(['email' => 'taken@shop.local']);

        app(BusinessContext::class)->forgetBusiness();

        try {
            app(OnboardBusiness::class)->handle(
                businessName: 'محل جديد',
                ownerName: 'مالك',
                // Already used: email is unique across the platform.
                ownerEmail: 'taken@shop.local',
                ownerPassword: 'password',
            );
            $this->fail('A duplicate email should have stopped onboarding.');
        } catch (\Throwable $e) {
            // expected
        }

        // Only the business from the test setup exists; no half-made shop.
        $this->assertSame(1, Business::count());
        $this->assertSame($existing->id, Business::firstOrFail()->id);
    }

    public function test_onboarding_restores_whatever_tenant_was_already_in_context(): void
    {
        // The platform panel runs this while serving an admin, and it must not
        // leave the container pointing at the shop it just created.
        $current = $this->tenant();

        app(OnboardBusiness::class)->handle(
            businessName: 'محل تالت',
            ownerName: 'مالك',
            ownerEmail: 'third@shop.local',
            ownerPassword: 'password',
        );

        $this->assertSame($current->id, app(BusinessContext::class)->businessId());

        // And the new shop's rows did not leak into the current tenant.
        $this->assertSame(0, ProductVariant::count());
        $this->assertSame(0, User::count());
    }

    protected function tearDown(): void
    {
        app(BusinessContext::class)->forgetBusiness();

        parent::tearDown();
    }
}
