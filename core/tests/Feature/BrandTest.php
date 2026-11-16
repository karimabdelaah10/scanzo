<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Branch;
use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BrandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    private function owner(): User
    {
        $business = $this->tenant();
        Branch::factory()->create();

        return User::factory()->owner()->create(['business_id' => $business->id]);
    }

    private function svg(string $body): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10">'.$body.'</svg>');
    }

    // ── colours ─────────────────────────────────────────────────────────

    public function test_the_owner_saves_both_colours_and_me_returns_them_with_derived_foregrounds(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner)->patchJson('/api/settings', ['brand' => ['primary' => '#f7e59b', 'secondary' => '#101418']])
            ->assertOk()
            ->assertJsonPath('data.brand.primary', '#F7E59B');

        $brand = $this->actingAs($owner)->getJson('/api/me')->json('business.brand');

        $this->assertSame('#F7E59B', $brand['primary']);
        $this->assertSame('#101418', $brand['secondary']);
        // Derived, not chosen: dark text on the pale yellow, white on the near-black.
        $this->assertSame('#0F2A44', $brand['accent_fg']);
        $this->assertSame('#FFFFFF', $brand['side_fg']);
        $this->assertFalse($brand['adjusted']['primary']);

        // …and never stored.
        $stored = $owner->business->fresh()->settings['brand'];
        $this->assertArrayNotHasKey('accent_fg', $stored);
        $this->assertArrayNotHasKey('side_fg', $stored);
    }

    public function test_malformed_hex_is_rejected_and_nothing_is_saved(): void
    {
        $owner = $this->owner();

        foreach (['red', '#abc', '#12345', '#GGGGGG', 'F7E59B', '#F7E59B; background:url(x)'] as $bad) {
            $this->actingAs($owner)->patchJson('/api/settings', ['brand' => ['primary' => $bad]])
                ->assertStatus(422)
                ->assertJsonValidationErrors('brand.primary');
        }

        $this->actingAs($owner)->patchJson('/api/settings', ['brand' => ['secondary' => '#12']])
            ->assertStatus(422)
            ->assertJsonValidationErrors('brand.secondary');

        $this->assertNull($owner->business->fresh()->setting('brand.primary'));
    }

    public function test_a_colour_can_be_cleared_back_to_the_platform_palette(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner)->patchJson('/api/settings', ['brand' => ['primary' => '#336699']])->assertOk();

        $this->actingAs($owner)->patchJson('/api/settings', ['brand' => ['primary' => null]])->assertOk();

        $brand = $this->actingAs($owner)->getJson('/api/me')->json('business.brand');
        $this->assertNull($brand['primary']);
        $this->assertNull($brand['accent']);
    }

    public function test_the_preview_reports_when_a_colour_had_to_be_adjusted(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner)->getJson('/api/brand/preview?primary=%238A8A8A&secondary=%23F7E59B')
            ->assertOk()
            ->assertJsonPath('data.adjusted.primary', true)
            ->assertJsonPath('data.adjusted.secondary', false);
    }

    public function test_a_seller_and_a_branch_manager_cannot_write_brand_settings_but_the_owner_can(): void
    {
        $owner = $this->owner();
        $branch = Branch::query()->first();
        $manager = $this->manager($branch);
        $seller = User::factory()->seller($branch)->create();

        foreach ([$seller, $manager] as $user) {
            $this->actingAs($user)->patchJson('/api/settings', ['brand' => ['primary' => '#336699']])->assertForbidden();
            $this->actingAs($user)->postJson('/api/settings/logo', ['logo' => UploadedFile::fake()->image('l.png', 50, 50)])->assertForbidden();
            $this->actingAs($user)->deleteJson('/api/settings/logo')->assertForbidden();
        }

        $this->assertNull($owner->business->fresh()->setting('brand.primary'));

        $this->actingAs($owner)->patchJson('/api/settings', ['brand' => ['primary' => '#336699']])->assertOk();
        $this->assertSame('#336699', $owner->business->fresh()->setting('brand.primary'));
    }

    // ── logo ────────────────────────────────────────────────────────────

    public function test_a_valid_png_is_accepted_and_stored_under_the_business_folder(): void
    {
        $owner = $this->owner();

        $path = $this->actingAs($owner)
            ->postJson('/api/settings/logo', ['logo' => UploadedFile::fake()->image('logo.png', 200, 100)])
            ->assertOk()
            ->assertJsonPath('data.brand.logo', fn ($url) => str_starts_with($url, "/storage/brands/{$owner->business_id}/"))
            ->json('data.brand.logo');

        $stored = $owner->business->fresh()->setting('brand.logo');
        $this->assertStringStartsWith("brands/{$owner->business_id}/", $stored);
        Storage::disk('public')->assertExists($stored);
        $this->assertSame('/storage/'.$stored, $path);
    }

    public function test_an_oversized_file_a_wrong_mime_and_a_huge_image_are_rejected(): void
    {
        $owner = $this->owner();

        $tooBig = UploadedFile::fake()->image('big.png', 100, 100)->size(600);
        $notAnImage = UploadedFile::fake()->create('logo.pdf', 10, 'application/pdf');
        $gif = UploadedFile::fake()->image('logo.gif', 50, 50);
        $tooWide = UploadedFile::fake()->image('wide.png', 1001, 50);

        foreach ([$tooBig, $notAnImage, $gif, $tooWide] as $file) {
            $this->actingAs($owner)->postJson('/api/settings/logo', ['logo' => $file])
                ->assertStatus(422)
                ->assertJsonValidationErrors('logo');
        }

        $this->assertNull($owner->business->fresh()->setting('brand.logo'));
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_an_svg_with_script_or_event_handlers_is_rejected_and_a_clean_one_accepted(): void
    {
        $owner = $this->owner();

        foreach ([
            '<script>alert(1)</script>',
            '<rect width="5" height="5" onload="alert(1)"/>',
            '<rect width="5" height="5" onclick = "x()"/>',
            '<a href="javascript:alert(1)"><rect width="5" height="5"/></a>',
            '<foreignObject><div/></foreignObject>',
        ] as $body) {
            $this->actingAs($owner)->postJson('/api/settings/logo', ['logo' => $this->svg($body)])
                ->assertStatus(422)
                ->assertJsonValidationErrors('logo');
        }

        $this->assertSame([], Storage::disk('public')->allFiles());

        $this->actingAs($owner)->postJson('/api/settings/logo', ['logo' => $this->svg('<rect width="5" height="5" fill="#123456"/>')])
            ->assertOk();
    }

    public function test_replacing_a_logo_deletes_the_previous_file(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner)->postJson('/api/settings/logo', ['logo' => UploadedFile::fake()->image('a.png', 40, 40)])->assertOk();
        $first = $owner->business->fresh()->setting('brand.logo');

        $this->actingAs($owner)->postJson('/api/settings/logo', ['logo' => UploadedFile::fake()->image('b.png', 40, 40)])->assertOk();
        $second = $owner->business->fresh()->setting('brand.logo');

        $this->assertNotSame($first, $second);
        Storage::disk('public')->assertMissing($first);
        Storage::disk('public')->assertExists($second);
        $this->assertCount(1, Storage::disk('public')->allFiles());
    }

    public function test_removing_the_logo_deletes_the_file_too(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner)->postJson('/api/settings/logo', ['logo' => UploadedFile::fake()->image('a.png', 40, 40)])->assertOk();
        $path = $owner->business->fresh()->setting('brand.logo');

        $this->actingAs($owner)->deleteJson('/api/settings/logo')->assertOk();

        Storage::disk('public')->assertMissing($path);
        $this->assertNull($owner->business->fresh()->setting('brand.logo'));
    }

    public function test_a_logo_cannot_reach_another_shops_folder(): void
    {
        $owner = $this->owner();
        $other = Business::factory()->create();
        $this->actingAs($owner)->postJson('/api/settings/logo', ['logo' => UploadedFile::fake()->image('a.png', 40, 40)])->assertOk();

        $this->assertEmpty(Storage::disk('public')->allFiles("brands/{$other->id}"));
        $this->assertNotEmpty(Storage::disk('public')->allFiles("brands/{$owner->business_id}"));
    }

    // ── the platform panel ──────────────────────────────────────────────

    public function test_the_platform_panel_sets_colours_and_logo_and_replaces_the_logo(): void
    {
        $business = Business::factory()->create();
        Auth::guard('admin')->setUser(Admin::factory()->create());
        Auth::shouldUse('web');

        $this->put(route('admin.businesses.brand', $business), [
            'brand_primary' => '#336699',
            'brand_secondary' => '#F7E59B',
            'logo' => UploadedFile::fake()->image('a.png', 60, 60),
        ])->assertRedirect();

        $business->refresh();
        $this->assertSame('#336699', $business->setting('brand.primary'));
        $first = $business->setting('brand.logo');
        Storage::disk('public')->assertExists($first);

        $this->put(route('admin.businesses.brand', $business), ['logo' => UploadedFile::fake()->image('b.png', 60, 60)])->assertRedirect();

        Storage::disk('public')->assertMissing($first);
        $this->assertSame('#336699', $business->fresh()->setting('brand.primary'), 'colours not sent are left alone');

        $this->put(route('admin.businesses.brand', $business), ['brand_primary' => 'blue'])->assertSessionHasErrors('brand_primary');
        $this->put(route('admin.businesses.brand', $business), ['logo' => $this->svg('<script>1</script>')])->assertSessionHasErrors('logo');
    }

    public function test_the_admin_business_page_renders_the_brand_form_and_the_sidebar(): void
    {
        $business = Business::factory()->create();
        Auth::guard('admin')->setUser(Admin::factory()->create());
        Auth::shouldUse('web');

        $this->get(route('admin.businesses.show', $business))
            ->assertOk()
            ->assertSee('data-brand-form', false)
            ->assertSee('data-switcher', false)
            ->assertSee('aria-current="page"', false)
            ->assertSee('المحلات');
    }

    public function test_the_shops_dashboard_and_till_pages_carry_no_switcher(): void
    {
        $this->get('/till')->assertOk()->assertDontSee('data-switcher', false)->assertDontSee('Reem+Kufi', false);
        $this->get('/dashboard')->assertOk()->assertDontSee('data-switcher', false)->assertSee('Reem+Kufi', false);
    }

    // ── the receipt.logo → brand.logo migration ─────────────────────────

    private function migration(): object
    {
        return require database_path('migrations/2026_11_17_000100_move_receipt_logo_to_brand_logo.php');
    }

    public function test_the_migration_moves_receipt_logo_to_brand_logo_and_leaves_others_untouched(): void
    {
        $with = Business::factory()->create(['settings' => ['receipt' => ['header' => 'H', 'logo' => 'https://cdn.example/logo.png']]]);
        $without = Business::factory()->create(['settings' => ['receipt' => ['header' => 'only a header']]]);
        $emptyOld = Business::factory()->create(['settings' => ['receipt' => ['logo' => '']]]);

        $untouchedBefore = DB::table('businesses')->where('id', $without->id)->value('settings');

        $this->migration()->up();

        $moved = Business::find($with->id)->settings;
        $this->assertSame('https://cdn.example/logo.png', $moved['brand']['logo']);
        $this->assertArrayNotHasKey('logo', $moved['receipt']);
        $this->assertSame('H', $moved['receipt']['header']);

        $this->assertSame($untouchedBefore, DB::table('businesses')->where('id', $without->id)->value('settings'));
        $this->assertArrayNotHasKey('brand', Business::find($without->id)->settings ?? []);

        // The dead key goes even when there was nothing to move.
        $this->assertArrayNotHasKey('logo', Business::find($emptyOld->id)->settings['receipt']);
        $this->assertArrayNotHasKey('brand', Business::find($emptyOld->id)->settings);
    }

    public function test_a_moved_link_is_served_and_never_deleted_as_if_it_were_an_upload(): void
    {
        $business = Business::factory()->create(['settings' => ['receipt' => ['logo' => '/storage/old-logo.png']]]);
        $this->migration()->up();
        $owner = User::factory()->owner()->create(['business_id' => $business->id]);

        $this->actingAs($owner)->getJson('/api/me')->assertJsonPath('business.brand.logo', '/storage/old-logo.png');

        Storage::disk('public')->put('old-logo.png', 'x');
        $this->actingAs($owner)->postJson('/api/settings/logo', ['logo' => UploadedFile::fake()->image('n.png', 30, 30)])->assertOk();

        Storage::disk('public')->assertExists('old-logo.png');
    }

    public function test_the_receipt_reads_the_one_logo(): void
    {
        $this->assertStringNotContainsString('receipt.logo', file_get_contents(resource_path('js/dashboard/views/Settings.vue')));
        $this->assertStringContainsString('Brand::logoUrl', file_get_contents(app_path('Http/Resources/InvoiceResource.php')));
    }
}
