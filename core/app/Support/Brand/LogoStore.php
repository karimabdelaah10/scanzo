<?php

namespace App\Support\Brand;

use App\Models\Business;
use App\Rules\BrandLogo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Where a shop's logo lives on disk: `brands/{business_id}/` on the `public`
 * disk, served as /storage/brands/… once `php artisan storage:link` has run.
 *
 * The file name carries a random part, so a replaced logo is a new URL and no
 * browser or CDN keeps showing the old one.
 */
class LogoStore
{
    public function put(Business $business, UploadedFile $file): string
    {
        $extension = BrandLogo::MIMES[(string) $file->getMimeType()] ?? 'png';

        return $file->storeAs(
            "brands/{$business->id}",
            'logo-'.Str::lower(Str::random(12)).'.'.$extension,
            'public',
        );
    }

    /**
     * Delete a stored logo. Only paths this class wrote are touched: a value
     * moved over from the old `receipt.logo` can be an https link or a path on
     * the site, which is not ours to delete.
     */
    public function delete(mixed $path): void
    {
        if (is_string($path) && str_starts_with($path, 'brands/') && ! str_contains($path, '..')) {
            Storage::disk('public')->delete($path);
        }
    }
}
