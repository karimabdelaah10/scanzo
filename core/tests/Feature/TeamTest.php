<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Branch;
use App\Models\Device;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\Subscription;
use App\Models\User;
use App\Services\StockLedger;
use App\Support\BusinessContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeamTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_owner_adds_a_branch_a_seller_and_a_till(): void
    {
        $this->tenant();
        $owner = User::factory()->owner()->create();
        Branch::factory()->create(['code' => 'MAIN']);

        $branch = $this->actingAs($owner)->postJson('/api/branches', [
            'name' => 'فرع العصافرة',
            'code' => 'asf',
            'phone' => '03000000',
        ])
            ->assertCreated()
            // Codes are stored upper case whatever was typed.
            ->assertJsonPath('data.code', 'ASF')
            ->json('data.id');

        $this->actingAs($owner)->postJson('/api/users', [
            'name' => 'بائع العصافرة',
            'email' => 'seller2@shop.local',
            'password' => 'password123',
            'role' => 'seller',
            'branch_id' => $branch,
        ])
            ->assertCreated()
            ->assertJsonPath('data.role', 'seller')
            ->assertJsonPath('data.branch_id', $branch);

        $this->actingAs($owner)->postJson('/api/devices', [
            'name' => 'كاشير العصافرة',
            'branch_id' => $branch,
        ])
            ->assertCreated()
            // Generated, because a shop inventing a duplicate would give two
            // tills the same invoice numbers.
            ->assertJsonPath('data.code', 'A1')
            ->assertJsonPath('data.invoice_prefix', 'A1-');

        // The next one carries on from there.
        $this->actingAs($owner)->postJson('/api/devices', [
            'name' => 'كاشير تاني',
            'branch_id' => $branch,
        ])->assertCreated()->assertJsonPath('data.code', 'A2');
    }

    public function test_an_owner_is_never_tied_to_a_branch(): void
    {
        $this->tenant();
        $owner = User::factory()->owner()->create();
        $branch = Branch::factory()->create();

        $this->actingAs($owner)->postJson('/api/users', [
            'name' => 'شريك',
            'email' => 'partner@shop.local',
            'password' => 'password123',
            'role' => 'owner',
            // Sent anyway, and ignored.
            'branch_id' => $branch->id,
        ])
            ->assertCreated()
            ->assertJsonPath('data.branch_id', null);
    }

    public function test_everyone_but_an_owner_needs_a_branch(): void
    {
        $this->tenant();
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)->postJson('/api/users', [
            'name' => 'بائع',
            'email' => 'nobranch@shop.local',
            'password' => 'password123',
            'role' => 'seller',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('branch_id');
    }

    public function test_the_plan_caps_what_can_be_added(): void
    {
        $business = $this->tenant();
        Subscription::create([
            'plan' => 'basic',
            'starts_at' => now(),
            'ends_at' => now()->addYear(),
            'max_branches' => 2,
            'max_users' => 2,
            'max_devices' => 1,
        ]);

        $owner = User::factory()->owner()->create();
        $main = Branch::factory()->create();

        // The owner is user one of two.
        $this->actingAs($owner)->postJson('/api/users', [
            'name' => 'بائع',
            'email' => 'two@shop.local',
            'password' => 'password123',
            'role' => 'seller',
            'branch_id' => $main->id,
        ])->assertCreated();

        $this->actingAs($owner)->postJson('/api/users', [
            'name' => 'بائع تالت',
            'email' => 'three@shop.local',
            'password' => 'password123',
            'role' => 'seller',
            'branch_id' => $main->id,
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'plan_limit_reached')
            ->assertJsonPath('context.what', 'users')
            ->assertJsonPath('context.limit', 2);

        $this->actingAs($owner)->postJson('/api/devices', [
            'name' => 'كاشير', 'branch_id' => $main->id,
        ])->assertCreated();

        $this->actingAs($owner)->postJson('/api/devices', [
            'name' => 'كاشير تاني', 'branch_id' => $main->id,
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'plan_limit_reached')
            ->assertJsonPath('context.what', 'devices');

        $this->actingAs($owner)->getJson('/api/settings')
            ->assertOk()
            ->assertJsonPath('usage.users.used', 2)
            ->assertJsonPath('usage.users.limit', 2)
            ->assertJsonPath('usage.devices.limit', 1);
    }

    public function test_a_switched_off_account_frees_a_place_on_the_plan(): void
    {
        // A shop that replaced a seller should not have to delete the old
        // record in order to hire the new one.
        $this->tenant();
        Subscription::create([
            'plan' => 'basic', 'starts_at' => now(), 'max_users' => 2,
        ]);

        $owner = User::factory()->owner()->create();
        $branch = Branch::factory()->create();
        $left = User::factory()->seller($branch)->create();

        $this->actingAs($owner)->patchJson('/api/users/'.$left->id, ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->actingAs($owner)->postJson('/api/users', [
            'name' => 'بائع جديد',
            'email' => 'replacement@shop.local',
            'password' => 'password123',
            'role' => 'seller',
            'branch_id' => $branch->id,
        ])->assertCreated();
    }

    public function test_a_business_can_never_be_left_without_an_owner(): void
    {
        $this->tenant();
        $owner = User::factory()->owner()->create();
        $second = User::factory()->owner()->create();
        $branch = Branch::factory()->create();

        // Two owners: demoting the other one is fine.
        $this->actingAs($owner)->patchJson('/api/users/'.$second->id, [
            'role' => 'branch_manager',
            'branch_id' => $branch->id,
        ])->assertOk();

        // The one left cannot switch themselves off, or give up the role.
        $this->actingAs($owner)->patchJson('/api/users/'.$owner->id, ['is_active' => false])
            ->assertForbidden()
            ->assertJsonPath('code', 'own_account');

        $this->actingAs($owner)->patchJson('/api/users/'.$owner->id, ['role' => 'seller'])
            ->assertForbidden()
            ->assertJsonPath('code', 'own_account');

        // $second was demoted through the API, so the in-memory object still
        // says owner. fresh() reloads it, otherwise the gate passes it as one.
        $this->actingAs($second->fresh())->patchJson('/api/users/'.$owner->id, ['is_active' => false])
            ->assertForbidden()
            ->assertJsonPath('code', 'business_not_allowed');
    }

    public function test_a_switched_off_owner_can_be_demoted(): void
    {
        // B is not counted as an owner any more, so demoting B leaves A as
        // the one active owner and must not be refused.
        $this->tenant();
        $a = User::factory()->owner()->create();
        $b = User::factory()->owner()->create();
        $branch = Branch::factory()->create();

        $this->actingAs($a)->patchJson('/api/users/'.$b->id, ['is_active' => false])
            ->assertOk();

        $this->actingAs($a)->patchJson('/api/users/'.$b->id, [
            'role' => 'branch_manager',
            'branch_id' => $branch->id,
        ])
            ->assertOk()
            ->assertJsonPath('data.role', 'branch_manager');
    }

    public function test_a_switched_off_owner_can_be_deleted(): void
    {
        $this->tenant();
        $a = User::factory()->owner()->create();
        $c = User::factory()->owner()->create(['is_active' => false]);

        // No invoices, so userHasHistory does not get in the way.
        $this->actingAs($a)->deleteJson('/api/users/'.$c->id)->assertNoContent();
    }

    public function test_a_switched_off_owner_with_a_live_session_is_refused_everywhere(): void
    {
        // This used to be the one way to reach `last_owner`: A is switched off
        // by B but keeps a session, then removes B. EnsureAccountIsActive now
        // stops A at the door, so the guard is only reachable by a race
        // between two owners; the rule itself has not changed.
        $this->tenant();
        $a = User::factory()->owner()->create();
        $b = User::factory()->owner()->create();
        $branch = Branch::factory()->create();

        $this->actingAs($b)->patchJson('/api/users/'.$a->id, ['is_active' => false])
            ->assertOk();

        $this->actingAs($a->fresh())->patchJson('/api/users/'.$b->id, [
            'role' => 'branch_manager',
            'branch_id' => $branch->id,
        ])
            ->assertUnauthorized()
            ->assertJsonPath('code', 'account_inactive');

        $this->actingAs($a->fresh())->deleteJson('/api/users/'.$b->id)
            ->assertUnauthorized()
            ->assertJsonPath('code', 'account_inactive');

        $this->assertSame(Role::Owner, $b->fresh()->role);
    }

    public function test_a_switched_off_account_is_refused_on_every_protected_endpoint(): void
    {
        $this->tenant();
        [$branch, , $seller] = $this->shopFloor();
        $manager = $this->manager($branch);
        $owner = User::factory()->owner()->create();

        foreach ([$seller, $manager, $owner] as $user) {
            $user->update(['is_active' => false]);
            $user = $user->fresh();

            foreach (['me', 'branches', 'devices', 'stock', 'products', 'settings'] as $path) {
                $this->actingAs($user)->getJson('/api/'.$path)
                    ->assertUnauthorized()
                    ->assertJsonPath('code', 'account_inactive');
            }

            $this->actingAs($user)->postJson('/api/logout')
                ->assertUnauthorized()
                ->assertJsonPath('code', 'account_inactive');
        }
    }

    public function test_a_switched_off_account_loses_its_session(): void
    {
        $this->tenant();
        $owner = User::factory()->owner()->create();
        $manager = User::factory()->branchManager(Branch::factory()->create())->create();

        $this->actingAs($manager)->getJson('/api/me')->assertOk();

        $manager->update(['is_active' => false]);

        $this->actingAs($manager->fresh())->getJson('/api/me')->assertUnauthorized();
        $this->assertGuest('web');

        // The owner is untouched.
        $this->actingAs($owner)->getJson('/api/me')->assertOk();
    }

    public function test_the_receipt_text_is_saved_and_the_receipt_has_no_logo_field_of_its_own(): void
    {
        $this->tenant();
        $owner = User::factory()->owner()->create();

        // `receipt.logo` was merged into `brand.logo` (one logo for the sidebar,
        // the till and the receipt). Sending the old key must not resurrect it.
        $this->actingAs($owner)->patchJson('/api/settings', [
            'receipt' => [
                'header' => 'أهلاً بيكم',
                'footer' => 'شكراً لزيارتكم',
                'logo' => 'https://cdn.shop.example/logo.png',
            ],
        ])
            ->assertOk()
            ->assertJsonPath('data.receipt.header', 'أهلاً بيكم')
            ->assertJsonPath('data.receipt.footer', 'شكراً لزيارتكم')
            ->assertJsonMissingPath('data.receipt.logo')
            ->assertJsonPath('data.brand.logo', null);

        $this->assertArrayNotHasKey('logo', $owner->business->fresh()->settings['receipt']);

        // Sending only the footer leaves the header alone.
        $this->actingAs($owner)->patchJson('/api/settings', ['receipt' => ['footer' => 'تاني']])
            ->assertOk()
            ->assertJsonPath('data.receipt.header', 'أهلاً بيكم');
    }

    public function test_nobody_can_lock_themselves_out(): void
    {
        $this->tenant();
        $owner = User::factory()->owner()->create();
        User::factory()->owner()->create();

        $this->actingAs($owner)->patchJson('/api/users/'.$owner->id, ['role' => 'seller'])
            ->assertForbidden()
            ->assertJsonPath('code', 'own_account');

        $this->actingAs($owner)->deleteJson('/api/users/'.$owner->id)
            ->assertForbidden()
            ->assertJsonPath('code', 'own_account');
    }

    public function test_a_seller_who_has_sold_is_switched_off_not_deleted(): void
    {
        $this->tenant();
        $owner = User::factory()->owner()->create();
        [$branch, $device, $seller] = $this->shopFloor();

        $variant = ProductVariant::factory()->create();
        $this->giveStock($variant, $branch, 3);
        $this->sell($seller, $device, [['variant_id' => $variant->id, 'quantity' => 1]]);

        $this->actingAs($owner)->deleteJson('/api/users/'.$seller->id)
            ->assertStatus(422)
            ->assertJsonPath('code', 'user_has_history');

        $this->actingAs($owner)->patchJson('/api/users/'.$seller->id, ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        // A seller who never sold can go.
        $unused = User::factory()->seller($branch)->create();
        $this->actingAs($owner)->deleteJson('/api/users/'.$unused->id)->assertNoContent();
    }

    public function test_a_branch_that_received_stock_but_never_sold_is_not_deleted(): void
    {
        $this->tenant();
        $owner = User::factory()->owner()->create();
        Branch::factory()->create(['code' => 'MAIN']);
        $branch = Branch::factory()->create(['code' => 'ASF']);

        $variant = ProductVariant::factory()->create();
        $this->giveStock($variant, $branch, 5);

        $this->actingAs($owner)->deleteJson('/api/branches/'.$branch->id)
            ->assertStatus(422)
            ->assertJsonPath('code', 'branch_has_stock_history');

        // The branch and its ledger are still there, and so is the quantity.
        $this->assertDatabaseHas('branches', ['id' => $branch->id]);
        $this->assertSame(1, StockMovement::where('branch_id', $branch->id)->count());
        $this->assertSame(5, app(StockLedger::class)->balance($variant->id, $branch->id));

        // Switching it off is the way out.
        $this->actingAs($owner)->patchJson('/api/branches/'.$branch->id, ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);
    }

    public function test_an_empty_branch_can_still_be_deleted(): void
    {
        $this->tenant();
        $owner = User::factory()->owner()->create();
        Branch::factory()->create(['code' => 'MAIN']);
        $empty = Branch::factory()->create(['code' => 'OOPS']);

        $this->actingAs($owner)->deleteJson('/api/branches/'.$empty->id)->assertNoContent();
    }

    public function test_the_shop_currency_and_label_size_reach_the_front_end(): void
    {
        $this->tenant();
        $owner = User::factory()->owner()->create();
        Branch::factory()->create();

        $this->actingAs($owner)->getJson('/api/me')
            ->assertJsonPath('business.currency', 'EGP')
            ->assertJsonPath('business.label_size', 'roll-40x30');

        $this->actingAs($owner)->patchJson('/api/settings', ['currency' => 'sar', 'labels' => ['size' => 'a4-24']])
            ->assertOk();

        $this->actingAs($owner)->getJson('/api/me')
            ->assertJsonPath('business.currency', 'SAR')
            ->assertJsonPath('business.label_size', 'a4-24');

        // A value that is not a template is refused rather than stored.
        $this->actingAs($owner)->patchJson('/api/settings', ['labels' => ['size' => '40x30mm']])
            ->assertJsonValidationErrors('labels.size');
    }

    public function test_a_till_that_has_sold_keeps_its_code_forever(): void
    {
        $this->tenant();
        $owner = User::factory()->owner()->create();
        [$branch, $device, $seller] = $this->shopFloor();

        $variant = ProductVariant::factory()->create();
        $this->giveStock($variant, $branch, 3);
        $this->sell($seller, $device, [['variant_id' => $variant->id, 'quantity' => 1]]);

        // Its code is in the number of every invoice it wrote.
        $this->actingAs($owner)->deleteJson('/api/devices/'.$device->id)
            ->assertStatus(422)
            ->assertJsonPath('code', 'device_has_history');

        $this->actingAs($owner)->patchJson('/api/devices/'.$device->id, ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);
    }

    public function test_the_last_branch_cannot_be_removed(): void
    {
        $this->tenant();
        $owner = User::factory()->owner()->create();
        $only = Branch::factory()->create();

        $this->actingAs($owner)->deleteJson('/api/branches/'.$only->id)
            ->assertStatus(422)
            ->assertJsonPath('code', 'last_branch');
    }

    public function test_a_manager_and_a_seller_see_only_their_own_branch(): void
    {
        $this->tenant();
        $mine = Branch::factory()->create();
        $theirs = Branch::factory()->create();
        Device::factory()->create(['branch_id' => $mine->id]);
        Device::factory()->create(['branch_id' => $theirs->id]);

        $manager = $this->manager($mine);

        $this->actingAs($manager)->getJson('/api/branches')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mine->id);

        $this->actingAs($manager)->getJson('/api/devices')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_only_the_owner_changes_the_shop(): void
    {
        $this->tenant();
        $branch = Branch::factory()->create();
        $manager = $this->manager($branch);

        $this->actingAs($manager)->postJson('/api/branches', ['name' => 'فرع', 'code' => 'NEW'])
            ->assertForbidden()
            ->assertJsonPath('code', 'business_not_allowed');

        $this->actingAs($manager)->getJson('/api/users')->assertForbidden();

        $this->actingAs($manager)->patchJson('/api/settings', ['name' => 'اسم تاني'])
            ->assertForbidden();

        // Reading the settings is fine: the till needs them.
        $this->actingAs($manager)->getJson('/api/settings')->assertOk();
    }

    public function test_one_setting_does_not_reset_the_others(): void
    {
        $this->tenant();
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)->patchJson('/api/settings', [
            'returns' => ['window_days' => 30, 'sellers_allowed' => true],
            'selling' => ['quick_scan' => true],
        ])->assertOk();

        // Sending only one switch leaves everything else as it was.
        $this->actingAs($owner)->patchJson('/api/settings', [
            'returns' => ['window_days' => 7],
        ])
            ->assertOk()
            ->assertJsonPath('data.returns.window_days', 7)
            ->assertJsonPath('data.returns.sellers_allowed', true)
            ->assertJsonPath('data.selling.quick_scan', true);
    }

    public function test_a_user_from_another_business_is_not_reachable(): void
    {
        $this->tenant();
        $otherBranch = Branch::factory()->create();
        $otherUser = User::factory()->seller($otherBranch)->create();

        $this->tenant();
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)->patchJson('/api/users/'.$otherUser->id, ['name' => 'x'])
            ->assertNotFound();

        $this->actingAs($owner)->getJson('/api/users')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    protected function tearDown(): void
    {
        app(BusinessContext::class)->forgetBusiness();

        parent::tearDown();
    }
}
