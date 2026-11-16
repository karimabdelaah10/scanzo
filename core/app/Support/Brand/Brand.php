<?php

namespace App\Support\Brand;

use App\Models\Business;

/**
 * What a business looks like, resolved for the front end.
 *
 * The owner's picks live in `settings.brand` (primary, secondary, logo). The
 * foregrounds and adjusted backgrounds are derived here on every call and never
 * stored (see Contrast). There is deliberately no state colour in this payload:
 * danger, warning and success are the same in every shop, so a red brand can
 * never make an error look like a button.
 */
class Brand
{
    /**
     * @return array{primary: ?string, secondary: ?string, logo: ?string, accent: ?string, accent_fg: ?string, accent_text: ?string, side: ?string, side_fg: ?string, adjusted: array{primary: bool, secondary: bool}}
     */
    public static function for(Business $business): array
    {
        return self::resolve(
            $business->setting('brand.primary'),
            $business->setting('brand.secondary'),
            self::logoUrl($business),
        );
    }

    /**
     * The URL a browser can load the logo from. A stored upload is a path on
     * the public disk; a value carried over from the old `receipt.logo` field
     * is already a link (https, or a path on this site) and is returned as is.
     * Relative on purpose: it must work under whatever host the shop uses, not
     * the one APP_URL names.
     */
    public static function logoUrl(Business $business): ?string
    {
        $logo = $business->setting('brand.logo');

        if (! is_string($logo) || $logo === '') {
            return null;
        }

        if (str_starts_with($logo, 'https://') || str_starts_with($logo, '/')) {
            return $logo;
        }

        return '/storage/'.ltrim($logo, '/');
    }

    /** The same derivation for colours not saved yet — the settings preview. */
    public static function resolve(?string $primary, ?string $secondary, ?string $logoUrl = null): array
    {
        $primary = Contrast::isHex($primary) ? Contrast::normalize($primary) : null;
        $secondary = Contrast::isHex($secondary) ? Contrast::normalize($secondary) : null;

        $accent = $primary ? Contrast::surface($primary) : null;
        $side = $secondary ? Contrast::surface($secondary) : null;

        return [
            'primary' => $primary,
            'secondary' => $secondary,
            'logo' => $logoUrl,
            // Null means "the shop picked nothing: keep the platform palette".
            'accent' => $accent['color'] ?? null,
            'accent_fg' => $accent['foreground'] ?? null,
            'accent_text' => $primary ? Contrast::asText($primary) : null,
            'side' => $side['color'] ?? null,
            'side_fg' => $side['foreground'] ?? null,
            'adjusted' => [
                'primary' => $accent['adjusted'] ?? false,
                'secondary' => $side['adjusted'] ?? false,
            ],
        ];
    }
}
