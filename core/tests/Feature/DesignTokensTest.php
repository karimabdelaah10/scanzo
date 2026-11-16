<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\User;
use App\Support\Brand\Brand;
use App\Support\Brand\Contrast;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The design system's guarantees, checked against the files themselves:
 * one place defines tokens, a palette or a brand cannot touch a state colour,
 * and every palette is readable.
 */
class DesignTokensTest extends TestCase
{
    use RefreshDatabase;

    private const STATE = ['--c-danger', '--c-danger-bg', '--c-warn', '--c-warn-bg', '--c-success', '--c-success-bg'];

    private function tokens(): string
    {
        return file_get_contents(public_path('css/tokens.css'));
    }

    /** @return array<string, array<string, string>> selector => [property => value] */
    private function blocks(string $css): array
    {
        $css = preg_replace('#/\*.*?\*/#s', '', $css);
        preg_match_all('/([^{}]+)\{([^{}]*)\}/', $css, $m, PREG_SET_ORDER);

        $blocks = [];
        foreach ($m as [, $selector, $body]) {
            preg_match_all('/(--[\w-]+)\s*:\s*([^;]+);/', $body, $props, PREG_SET_ORDER);
            foreach ($props as [, $name, $value]) {
                $blocks[trim($selector)][$name] = trim($value);
            }
        }

        return $blocks;
    }

    public function test_state_colours_are_declared_once_on_root_and_in_no_palette_block(): void
    {
        $blocks = $this->blocks($this->tokens());

        foreach (self::STATE as $name) {
            $this->assertArrayHasKey($name, $blocks[':root'], "$name must be declared on :root");
            $this->assertSame(1, substr_count($this->tokens(), "$name:"), "$name must be declared exactly once");
        }

        $palettes = array_filter(array_keys($blocks), fn ($s) => str_contains($s, 'data-palette'));
        $this->assertNotEmpty($palettes);

        foreach ($palettes as $selector) {
            foreach (self::STATE as $name) {
                $this->assertArrayNotHasKey($name, $blocks[$selector], "palette block `$selector` must not override $name");
            }
        }
    }

    public function test_the_legacy_state_aliases_resolve_to_the_state_tokens(): void
    {
        $root = $this->blocks($this->tokens())[':root'];

        $this->assertSame('var(--c-danger)', $root['--bad']);
        $this->assertSame('var(--c-danger-bg)', $root['--bad-soft']);
        $this->assertSame('var(--c-warn)', $root['--warn']);
        $this->assertSame('var(--c-warn-bg)', $root['--warn-soft']);
    }

    public function test_no_brand_path_can_write_a_state_colour(): void
    {
        // 1. The client script only ever writes the properties in its map.
        $boot = file_get_contents(public_path('js/brand-boot.js'));
        preg_match('/var PROPS = \{(.*?)\};/s', $boot, $m);
        preg_match_all("/'(--[\w-]+)'/", $m[1], $written);

        $this->assertNotEmpty($written[1]);
        $this->assertSame([], array_intersect($written[1], self::STATE));

        // 2. The server never puts a state colour in the brand it sends.
        $brand = Brand::resolve('#FF0000', '#FF0000');
        foreach (array_keys($brand) as $key) {
            $this->assertStringNotContainsString('danger', $key);
            $this->assertStringNotContainsString('success', $key);
            $this->assertStringNotContainsString('warn', $key);
            $this->assertStringNotContainsString('bad', $key);
        }

        // 3. A shop whose brand is red still gets the platform's own danger colour,
        //    which is a different colour from its button.
        $danger = $this->blocks($this->tokens())[':root']['--c-danger'];
        $this->assertNotSame(strtoupper($brand['accent']), strtoupper($danger));
    }

    public function test_the_brand_a_shop_receives_has_no_state_keys_over_the_api(): void
    {
        $business = Business::factory()->create(['settings' => ['brand' => ['primary' => '#FF0000', 'secondary' => '#00AA00']]]);
        $owner = User::factory()->owner()->create(['business_id' => $business->id]);

        $keys = array_keys($this->actingAs($owner)->getJson('/api/me')->assertOk()->json('business.brand'));

        $this->assertEqualsCanonicalizing(
            ['primary', 'secondary', 'logo', 'accent', 'accent_fg', 'accent_text', 'side', 'side_fg', 'adjusted'],
            $keys,
        );
    }

    public function test_app_css_defines_no_tokens(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));

        $this->assertDoesNotMatchRegularExpression('/:root\s*\{/', $css);
        $this->assertDoesNotMatchRegularExpression('/^\s*--[\w-]+\s*:/m', $css, 'app.css declares a custom property');
    }

    public function test_every_page_links_the_token_file_with_the_version(): void
    {
        foreach (['till', 'dashboard', 'admin/layout'] as $view) {
            $this->assertStringContainsString(
                "asset('css/tokens.css') }}?v={{",
                file_get_contents(resource_path("views/$view.blade.php")),
                "$view must link tokens.css with ?v=",
            );
        }
        $this->assertNotEmpty(config('scanzo.assets_version'));
    }

    public function test_the_admin_panel_uses_the_body_font_token(): void
    {
        $layout = file_get_contents(resource_path('views/admin/layout.blade.php'));

        $this->assertStringContainsString('var(--f-body)', $layout);
        $this->assertStringNotContainsString('system-ui', $layout);
        $this->assertStringContainsString('Reem+Kufi', $layout);
        $this->assertStringNotContainsString('Reem+Kufi', file_get_contents(resource_path('views/till.blade.php')));
    }

    public function test_the_default_palette_is_navy_and_orange_and_gold_is_a_named_alternative(): void
    {
        $blocks = $this->blocks($this->tokens());

        $this->assertSame('#0F2A44', strtoupper($blocks[':root']['--c-deep']));
        $this->assertSame('#163756', strtoupper($blocks[':root']['--c-deep-2']));
        $this->assertSame('#E05614', strtoupper($blocks[':root']['--c-accent']));
        $this->assertArrayHasKey('html[data-palette="gold"]', $blocks);
        $this->assertSame('#C9A24A', strtoupper($blocks['html[data-palette="gold"]']['--c-accent']));
        $this->assertSame('navy', array_key_first(config('scanzo.admin_palettes')), 'the first palette is the default');
    }

    public function test_the_text_bearing_orange_clears_4_5_and_the_decorative_orange_clears_3(): void
    {
        $root = $this->blocks($this->tokens())[':root'];
        $orange = $root['--c-accent'];
        $fill = $root['--c-accent-fill'];

        // Why there are two: the true orange fails the text rule at BOTH polarities.
        $this->assertLessThan(Contrast::MIN_RATIO, Contrast::ratio($orange, Contrast::WHITE));
        $this->assertLessThan(Contrast::MIN_RATIO, Contrast::ratio($orange, Contrast::INK));

        // Text-bearing: the fill, under its foreground, and under nothing worse than the rule gives.
        $this->assertGreaterThanOrEqual(Contrast::MIN_RATIO, Contrast::ratio($root['--c-on-accent'], $fill), 'on-accent on the fill: '.round(Contrast::ratio($root['--c-on-accent'], $fill), 2));
        $this->assertSame(Contrast::surface($orange)['color'], strtoupper($fill), 'the fill is what Contrast::surface() derives from the orange');
        $this->assertGreaterThanOrEqual(Contrast::MIN_RATIO, Contrast::ratio($root['--c-accent-text'], $root['--c-ground']), 'orange as text on the ground');
        $this->assertGreaterThanOrEqual(Contrast::MIN_RATIO, Contrast::ratio($root['--c-accent-text'], $root['--c-surface']), 'orange as text on a card');

        // Decorative: the true orange, held to the 3:1 bar for large text, icons and borders.
        foreach (['--c-surface', '--c-ground', '--c-deep'] as $ground) {
            $this->assertGreaterThanOrEqual(3.0, Contrast::ratio($orange, $root[$ground]), "decorative orange on $ground: ".round(Contrast::ratio($orange, $root[$ground]), 2));
        }
    }

    public function test_the_brand_script_writes_the_adjusted_colour_to_the_fill_and_the_picked_one_to_the_decorative_token(): void
    {
        $boot = file_get_contents(public_path('js/brand-boot.js'));

        $this->assertMatchesRegularExpression("/accent:\s*'--c-accent-fill'/", $boot);
        $this->assertMatchesRegularExpression("/primary:\s*\[[^\]]*'--c-accent'[^\]]*\]/", $boot);
        $this->assertDoesNotMatchRegularExpression("/accent:\s*'--c-accent'/", $boot, 'the adjusted colour must not land on the decorative token');
    }

    public function test_the_switcher_sits_on_the_physical_left(): void
    {
        $layout = file_get_contents(resource_path('views/admin/layout.blade.php'));

        $this->assertMatchesRegularExpression('/\.switcher \{[^}]*left:max\(16px,env\(safe-area-inset-left,0px\)\)/', $layout);
        $this->assertDoesNotMatchRegularExpression('/\.switcher \{[^}]*inset-inline-start/', $layout);
        $this->assertMatchesRegularExpression('/\.switcher-panel \{[^}]*transform-origin:bottom left/', $layout);
        $this->assertStringNotContainsString('transform-origin:bottom right', $layout);
        $this->assertStringNotContainsString('100vh', $layout);
    }

    public function test_the_label_pages_no_longer_hard_code_teal(): void
    {
        foreach (['sheet', 'calibration'] as $page) {
            $view = file_get_contents(resource_path("views/labels/$page.blade.php"));

            $this->assertStringNotContainsStringIgnoringCase('#0f766e', $view);
            $this->assertStringContainsString('var(--c-accent-fill)', $view);
            $this->assertStringContainsString('var(--c-on-accent)', $view);
            $this->assertStringContainsString("asset('css/tokens.css')", $view);
        }
    }

    public function test_the_till_top_bar_is_branded_but_the_sale_buttons_are_not(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));

        $this->assertStringContainsString('.topbar:not(.plain) {', $css);
        $this->assertMatchesRegularExpression('/\.topbar:not\(\.plain\)[^{]*\{[^}]*var\(--side-bg\)/', $css);
        // The branded rules must never reach a primary button or a state colour.
        $this->assertDoesNotMatchRegularExpression('/\.topbar[^{]*\.btn\.primary[^{]*\{/', $css);
        $this->assertSame(1, preg_match('/\.btn\.primary \{ background: var\(--accent\); color: var\(--accent-fg\)/', $css));
    }

    public function test_the_switcher_palettes_match_the_token_file_and_are_all_readable(): void
    {
        $blocks = $this->blocks($this->tokens());
        $default = $blocks[':root'];

        foreach (config('scanzo.admin_palettes') as $id => $p) {
            $palette = $id === 'navy' ? $default : array_merge($default, $blocks["html[data-palette=\"$id\"]"] ?? $this->fail("no CSS block for palette $id"));

            $this->assertSame(strtoupper($p['deep']), strtoupper($palette['--c-deep']), "$id deep");
            $this->assertSame(strtoupper($p['accent']), strtoupper($palette['--c-accent']), "$id accent");

            // The fill is what carries text, so it is the fill that is measured;
            // --c-accent is decorative. An alternative that forgot to declare its
            // own fill would silently inherit the default palette's orange.
            $this->assertArrayHasKey('--c-accent-fill', $id === 'navy' ? $default : $blocks["html[data-palette=\"$id\"]"], "$id declares its own --c-accent-fill");

            $pairs = [
                'on-accent on accent-fill' => ['--c-on-accent', '--c-accent-fill'],
                'ink on ground' => ['--c-ink', '--c-ground'],
                'ink on surface' => ['--c-ink', '--c-surface'],
                'muted on ground' => ['--c-muted', '--c-ground'],
                'accent-text on surface' => ['--c-accent-text', '--c-surface'],
                'accent-text on ground' => ['--c-accent-text', '--c-ground'],
                'on-deep on deep' => ['--c-on-deep', '--c-deep'],
                'accent-on-deep on deep' => ['--c-accent-on-deep', '--c-deep'],
                'on-deep-muted on deep' => ['--c-on-deep-muted', '--c-deep'],
            ];

            foreach ($pairs as $label => [$fg, $bg]) {
                $this->assertGreaterThanOrEqual(
                    Contrast::MIN_RATIO,
                    Contrast::ratio($palette[$fg], $palette[$bg]),
                    "palette $id: $label is ".round(Contrast::ratio($palette[$fg], $palette[$bg]), 2),
                );
            }
        }
    }
}
