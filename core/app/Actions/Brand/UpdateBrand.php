<?php

namespace App\Actions\Brand;

use App\Models\Business;
use App\Support\Brand\Contrast;
use App\Support\Brand\LogoStore;
use Illuminate\Http\UploadedFile;

/**
 * The one place a business's brand is written, for the platform panel and the
 * shop's own settings alike.
 *
 * Only the colours in `$colours` are touched (a null clears one); the logo is
 * replaced when a file is given and removed when asked. Replacing deletes the
 * old file, after the new one is saved, so a failure never leaves the shop
 * with no logo and a file nobody points at.
 */
class UpdateBrand
{
    public function __construct(protected LogoStore $logos) {}

    /**
     * @param  array{primary?: ?string, secondary?: ?string}  $colours
     */
    public function handle(
        Business $business,
        array $colours = [],
        ?UploadedFile $logo = null,
        bool $removeLogo = false,
    ): Business {
        $settings = $business->settings ?? [];
        $brand = $settings['brand'] ?? [];
        $oldLogo = $brand['logo'] ?? null;

        foreach (['primary', 'secondary'] as $key) {
            if (array_key_exists($key, $colours)) {
                $brand[$key] = filled($colours[$key]) ? Contrast::normalize($colours[$key]) : null;
            }
        }

        $newPath = null;

        if ($logo !== null) {
            $newPath = $this->logos->put($business, $logo);
            $brand['logo'] = $newPath;
        } elseif ($removeLogo) {
            $brand['logo'] = null;
        }

        $settings['brand'] = $brand;
        $business->settings = $settings;
        $business->save();

        if (($newPath !== null || $removeLogo) && $oldLogo !== ($brand['logo'] ?? null)) {
            $this->logos->delete($oldLogo);
        }

        return $business;
    }
}
