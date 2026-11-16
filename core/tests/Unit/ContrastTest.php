<?php

namespace Tests\Unit;

use App\Support\Brand\Contrast;
use PHPUnit\Framework\TestCase;

class ContrastTest extends TestCase
{
    public function test_ratio_matches_the_wcag_reference_values(): void
    {
        $this->assertEqualsWithDelta(21.0, Contrast::ratio('#000000', '#FFFFFF'), 0.001);
        $this->assertEqualsWithDelta(1.0, Contrast::ratio('#336699', '#336699'), 0.001);
        // #767676 on white is the well-known smallest grey that passes AA (4.54).
        $this->assertEqualsWithDelta(4.54, Contrast::ratio('#767676', '#FFFFFF'), 0.01);
    }

    public function test_a_pale_yellow_gets_dark_text_not_white(): void
    {
        $surface = Contrast::surface('#F7E59B');

        $this->assertSame(Contrast::INK, $surface['foreground']);
        $this->assertNotSame(Contrast::WHITE, $surface['foreground']);
        $this->assertGreaterThanOrEqual(Contrast::MIN_RATIO, $surface['ratio']);
        // Dark text already reads on it, so the owner's colour is kept as picked.
        $this->assertSame('#F7E59B', $surface['color']);
        $this->assertFalse($surface['adjusted']);
    }

    public function test_a_near_black_gets_white_text(): void
    {
        $surface = Contrast::surface('#101418');

        $this->assertSame(Contrast::WHITE, $surface['foreground']);
        $this->assertGreaterThanOrEqual(Contrast::MIN_RATIO, $surface['ratio']);
        $this->assertFalse($surface['adjusted']);
    }

    public function test_a_mid_tone_that_reaches_neither_is_moved_until_it_does(): void
    {
        // #8A8A8A: white 3.45:1, ink 4.1:1 — neither reaches 4.5.
        $this->assertLessThan(Contrast::MIN_RATIO, Contrast::ratio(Contrast::WHITE, '#8A8A8A'));
        $this->assertLessThan(Contrast::MIN_RATIO, Contrast::ratio(Contrast::INK, '#8A8A8A'));

        $surface = Contrast::surface('#8A8A8A');

        $this->assertTrue($surface['adjusted']);
        $this->assertNotSame('#8A8A8A', $surface['color']);
        $this->assertGreaterThanOrEqual(Contrast::MIN_RATIO, Contrast::ratio($surface['foreground'], $surface['color']));
    }

    public function test_every_colour_in_a_sweep_ends_at_4_5_or_better(): void
    {
        foreach (range(0, 255, 17) as $r) {
            foreach (range(0, 255, 51) as $g) {
                foreach (range(0, 255, 51) as $b) {
                    $surface = Contrast::surface(Contrast::hex($r, $g, $b));

                    $this->assertGreaterThanOrEqual(
                        Contrast::MIN_RATIO,
                        $surface['ratio'],
                        sprintf('rgb(%d,%d,%d) came out at %s', $r, $g, $b, $surface['ratio']),
                    );
                    $this->assertGreaterThanOrEqual(
                        Contrast::MIN_RATIO,
                        Contrast::ratio($surface['foreground'], $surface['color']),
                    );
                }
            }
        }
    }

    public function test_as_text_darkens_a_pale_colour_until_it_reads_on_the_page(): void
    {
        $text = Contrast::asText('#F7E59B');

        $this->assertGreaterThanOrEqual(Contrast::MIN_RATIO, Contrast::ratio($text, '#FFFDF8'));
    }

    public function test_hex_validation(): void
    {
        $this->assertTrue(Contrast::isHex('#a1B2c3'));
        foreach (['a1b2c3', '#abc', '#12345g', '#1234567', '', null, 12] as $bad) {
            $this->assertFalse(Contrast::isHex($bad));
        }
    }
}
