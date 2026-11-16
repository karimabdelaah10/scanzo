<?php

namespace App\Support\Brand;

/**
 * WCAG 2.1 contrast, and the one rule that keeps a shop's brand readable.
 *
 * A shop owner will pick a pale yellow, and a seller will then read text on it
 * in bad light. So the text colour on a brand is never chosen, it is derived
 * here, at render time. Nothing this class returns is stored: a stored
 * derivation goes stale the moment the rule changes.
 */
class Contrast
{
    /** The minimum for text, WCAG AA. */
    public const MIN_RATIO = 4.5;

    /** Dark text for light brand colours. Same as `--c-ink` in public/css/tokens.css. */
    public const INK = '#0F2A44';

    public const WHITE = '#FFFFFF';

    /** How far one adjustment step moves a colour towards black or white. */
    private const STEP = 0.04;

    public static function isHex(mixed $value): bool
    {
        return is_string($value) && preg_match('/^#[0-9A-Fa-f]{6}$/', $value) === 1;
    }

    public static function normalize(string $hex): string
    {
        return strtoupper($hex);
    }

    /** @return array{0: int, 1: int, 2: int} */
    public static function rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');

        return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    }

    public static function hex(int $r, int $g, int $b): string
    {
        return sprintf('#%02X%02X%02X', max(0, min(255, $r)), max(0, min(255, $g)), max(0, min(255, $b)));
    }

    /** Relative luminance, 0 (black) to 1 (white). */
    public static function luminance(string $hex): float
    {
        [$r, $g, $b] = array_map(function (int $channel) {
            $c = $channel / 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }, self::rgb($hex));

        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    }

    public static function ratio(string $a, string $b): float
    {
        $la = self::luminance($a);
        $lb = self::luminance($b);

        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    /** Whichever of white or ink reads better on this background. */
    public static function foregroundFor(string $background): string
    {
        return self::ratio(self::WHITE, $background) >= self::ratio(self::INK, $background)
            ? self::WHITE
            : self::INK;
    }

    /**
     * A background to put text on, derived from the colour the owner picked.
     *
     * If neither white nor ink reaches 4.5:1 on it, the colour is moved in
     * small steps — towards black when white reads better, towards white when
     * ink does — until one does. That adjusted colour is for backgrounds only;
     * borders, tints and the logo frame keep the picked one.
     *
     * @return array{color: string, foreground: string, ratio: float, adjusted: bool}
     */
    public static function surface(string $picked): array
    {
        $picked = self::normalize($picked);
        $color = $picked;

        $towardsBlack = self::ratio(self::WHITE, $color) >= self::ratio(self::INK, $color);

        for ($i = 0; $i < 30; $i++) {
            $foreground = self::foregroundFor($color);
            $ratio = self::ratio($foreground, $color);

            if ($ratio >= self::MIN_RATIO) {
                return [
                    'color' => $color,
                    'foreground' => $foreground,
                    'ratio' => round($ratio, 2),
                    'adjusted' => $color !== $picked,
                ];
            }

            $color = self::mix($color, $towardsBlack ? '#000000' : '#FFFFFF', self::STEP);
        }

        // Unreachable in practice (black and white both pass), but a loop that
        // can fall through should end somewhere readable.
        $color = $towardsBlack ? '#000000' : '#FFFFFF';
        $foreground = self::foregroundFor($color);

        return [
            'color' => $color,
            'foreground' => $foreground,
            'ratio' => round(self::ratio($foreground, $color), 2),
            'adjusted' => true,
        ];
    }

    /**
     * The picked colour, darkened until it reads as text on `$ground` — for
     * links and focus rings, where the colour is the foreground, not the fill.
     */
    public static function asText(string $picked, string $ground = '#FFFDF8'): string
    {
        $color = self::normalize($picked);

        for ($i = 0; $i < 30 && self::ratio($color, $ground) < self::MIN_RATIO; $i++) {
            $color = self::mix($color, '#000000', self::STEP);
        }

        return $color;
    }

    /** `$amount` of the way from `$from` to `$to`. */
    public static function mix(string $from, string $to, float $amount): string
    {
        $a = self::rgb($from);
        $b = self::rgb($to);

        return self::hex(
            (int) round($a[0] + ($b[0] - $a[0]) * $amount),
            (int) round($a[1] + ($b[1] - $a[1]) * $amount),
            (int) round($a[2] + ($b[2] - $a[2]) * $amount),
        );
    }
}
