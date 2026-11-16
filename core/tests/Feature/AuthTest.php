<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Device;
use App\Models\User;
use App\Support\BusinessContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_signing_in_returns_everything_the_front_end_needs(): void
    {
        $business = $this->tenant(['name' => 'ملابس النخبة']);
        $branch = Branch::factory()->create(['name' => 'سموحة', 'code' => 'MAIN']);
        Device::factory()->create(['branch_id' => $branch->id, 'code' => 'A1']);
        $manager = User::factory()->branchManager($branch)->create([
            'email' => 'manager@demo.local',
            'password' => 'password',
        ]);

        // Nothing is signed in and no tenant is set: the next call has to work
        // on its own, the way a cold page load does.
        app(BusinessContext::class)->forgetBusiness();

        $this->postJson('/api/login', [
            'email' => 'manager@demo.local',
            'password' => 'password',
        ])
            ->assertOk()
            ->assertJsonPath('user.email', 'manager@demo.local')
            ->assertJsonPath('user.role', 'branch_manager')
            ->assertJsonPath('user.sees_all_branches', false)
            ->assertJsonPath('user.sees_cost', true)
            ->assertJsonPath('business.name', 'ملابس النخبة')
            ->assertJsonPath('business.return_window_days', 14)
            ->assertJsonCount(1, 'branches')
            ->assertJsonPath('branches.0.code', 'MAIN')
            ->assertJsonCount(1, 'devices')
            ->assertJsonPath('devices.0.code', 'A1');

        $this->assertAuthenticatedAs($manager);
    }

    public function test_an_owner_sees_every_branch_and_a_seller_only_their_own(): void
    {
        $this->tenant();
        $main = Branch::factory()->create();
        $second = Branch::factory()->create();
        Device::factory()->create(['branch_id' => $main->id]);
        Device::factory()->create(['branch_id' => $second->id]);

        $owner = User::factory()->owner()->create();
        $seller = User::factory()->seller($main)->create();

        $this->actingAs($owner)->getJson('/api/me')
            ->assertOk()
            ->assertJsonCount(2, 'branches')
            ->assertJsonCount(2, 'devices')
            ->assertJsonPath('user.sees_all_branches', true);

        $this->actingAs($seller)->getJson('/api/me')
            ->assertOk()
            ->assertJsonCount(1, 'branches')
            ->assertJsonCount(1, 'devices')
            ->assertJsonPath('branches.0.id', $main->id)
            ->assertJsonPath('user.sees_cost', false);
    }

    public function test_a_wrong_password_says_nothing_about_which_part_was_wrong(): void
    {
        $this->tenant();
        User::factory()->create(['email' => 'someone@demo.local', 'password' => 'password']);

        app(BusinessContext::class)->forgetBusiness();

        $this->postJson('/api/login', [
            'email' => 'someone@demo.local',
            'password' => 'not-the-password',
        ])->assertStatus(422)->assertJsonValidationErrors('email');

        // The same answer for an address that does not exist at all.
        $this->postJson('/api/login', [
            'email' => 'nobody@demo.local',
            'password' => 'password',
        ])->assertStatus(422)->assertJsonValidationErrors('email');

        $this->assertGuest();
    }

    public function test_a_switched_off_account_cannot_sign_in(): void
    {
        $this->tenant();
        User::factory()->create([
            'email' => 'gone@demo.local',
            'password' => 'password',
            'is_active' => false,
        ]);

        app(BusinessContext::class)->forgetBusiness();

        $this->postJson('/api/login', [
            'email' => 'gone@demo.local',
            'password' => 'password',
        ])->assertStatus(422);

        $this->assertGuest();
    }

    public function test_repeated_guesses_are_throttled(): void
    {
        $this->tenant();
        User::factory()->create(['email' => 'target@demo.local', 'password' => 'password']);

        app(BusinessContext::class)->forgetBusiness();
        RateLimiter::clear('target@demo.local|127.0.0.1');

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/login', [
                'email' => 'target@demo.local',
                'password' => 'wrong',
            ])->assertStatus(422);
        }

        // The sixth is refused before the password is even checked, so a
        // correct one at this point still does not get in.
        $this->postJson('/api/login', [
            'email' => 'target@demo.local',
            'password' => 'password',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');

        $this->assertGuest();

        RateLimiter::clear('target@demo.local|127.0.0.1');
    }

    public function test_the_api_is_closed_without_a_session(): void
    {
        $this->getJson('/api/me')->assertUnauthorized();
        $this->getJson('/api/products')->assertUnauthorized();
        $this->postJson('/api/invoices', [])->assertUnauthorized();
    }

    public function test_signing_out_ends_the_session(): void
    {
        $this->tenant();
        $user = User::factory()->owner()->create();

        $this->actingAs($user)->postJson('/api/logout')->assertOk();
        $this->assertGuest();
    }

    protected function tearDown(): void
    {
        app(BusinessContext::class)->forgetBusiness();

        parent::tearDown();
    }
}
