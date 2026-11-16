<?php

namespace Tests\Feature;

use App\Support\Brand\Brand;
use App\Support\Brand\Contrast;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * A ring (active nav, card hover, focus) is a non-text element: 3:1 is the bar.
 * It takes --c-accent-text, the contrast-adjusted colour, never the picked one.
 */
class RingContrastTest extends TestCase
{
    /** @return array<string, string> */
    private function root(): array
    {
        $css = preg_replace('#/\*.*?\*/#s', '', file_get_contents(public_path('css/tokens.css')));
        preg_match('/:root\s*\{([^{}]*)\}/', $css, $m);
        preg_match_all('/(--[\w-]+)\s*:\s*([^;]+);/', $m[1], $props, PREG_SET_ORDER);

        return array_column($props, 2, 1);
    }

    /** @return array<string, array{string}> */
    public static function paleBrands(): array
    {
        return ['pale yellow' => ['#FFF3B0'], 'pale mint' => ['#D9F5E5'], 'near white' => ['#FAFAFA'], 'pale gold' => ['#E8D9A0']];
    }

    #[DataProvider('paleBrands')]
    public function test_a_pale_brand_primary_still_gives_a_ring_that_clears_3_to_1(string $pale): void
    {
        $root = $this->root();
        $ring = Brand::resolve($pale, '#0F2A44')['accent_text'];

        // The picked colour is what would have vanished: that is the bug being fixed.
        $this->assertLessThan(3.0, Contrast::ratio($pale, $root['--c-surface']), 'the fixture is not pale enough to prove anything');

        foreach (['--c-surface', '--c-ground'] as $ground) {
            $this->assertGreaterThanOrEqual(3.0, Contrast::ratio($ring, $root[$ground]), "ring $ring on $ground");
        }
    }

    public function test_rings_and_focus_outlines_are_drawn_with_the_text_safe_accent(): void
    {
        $tokens = file_get_contents(public_path('css/tokens.css'));
        $this->assertMatchesRegularExpression('/--ring-hover:[^;]*var\(--c-accent-text\)/', $tokens);
        $this->assertMatchesRegularExpression('/--ring-selected:[^;]*var\(--c-accent-text\)/', $tokens);

        $files = [
            resource_path('css/app.css'), resource_path('css/dashboard.css'), resource_path('views/admin/layout.blade.php'),
            ...glob(resource_path('js/*/*.vue')), ...glob(resource_path('js/*/*/*.vue')),
        ];

        foreach ($files as $file) {
            // Outlines, border colours, and inset or zero-offset shadows are rings. A blurred,
            // offset glow (the admin button's hover) is decoration and keeps the picked colour.
            preg_match_all('/(?:outline|border-color|box-shadow\s*:\s*(?:inset\s+)?0\s+0\s+0)\s*[^;}]*var\(--(?:c-)?accent\)|box-shadow\s*:\s*inset[^;}]*var\(--(?:c-)?accent\)/', file_get_contents($file), $bad);
            $this->assertSame([], $bad[0], basename($file).' draws a ring with the unadjusted or fill accent');
        }
    }
}
