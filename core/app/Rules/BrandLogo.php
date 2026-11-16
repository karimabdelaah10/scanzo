<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

/**
 * A shop's logo upload: png, jpeg, webp or svg, up to 512KB and 1000px on the
 * long edge.
 *
 * An uploaded SVG is markup that renders in the owner's and every seller's
 * browser, so anything that can run code is refused. That is stricter than
 * `<script` and `onload`: any `on…=` handler, `javascript:` links,
 * `<foreignObject>` and `<iframe>` go too, because a logo never needs them.
 */
class BrandLogo implements ValidationRule
{
    public const MAX_BYTES = 512 * 1024;

    public const MAX_EDGE = 1000;

    public const MIMES = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/webp' => 'webp',
        'image/svg+xml' => 'svg',
    ];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile || ! $value->isValid()) {
            $fail('الملف ما اترفعش صح. جرّب تاني.');

            return;
        }

        if ($value->getSize() > self::MAX_BYTES) {
            $fail('اللوجو أكبر من 512 كيلوبايت.');

            return;
        }

        $mime = $value->getMimeType();

        if (! array_key_exists((string) $mime, self::MIMES)) {
            $fail('اللوجو لازم يكون PNG أو JPEG أو WebP أو SVG.');

            return;
        }

        if ($mime === 'image/svg+xml') {
            $this->checkSvg($value, $fail);

            return;
        }

        $size = @getimagesize($value->getRealPath());

        if ($size === false) {
            $fail('الملف مش صورة سليمة.');

            return;
        }

        if (max($size[0], $size[1]) > self::MAX_EDGE) {
            $fail('أطول ضلع في اللوجو لازم يكون 1000 بكسل أو أقل.');
        }
    }

    private function checkSvg(UploadedFile $file, Closure $fail): void
    {
        $svg = (string) file_get_contents($file->getRealPath());

        if (! preg_match('/<\s*svg\b/i', $svg)) {
            $fail('الملف مش SVG سليم.');

            return;
        }

        if (preg_match('/<\s*script|\bon[a-z]+\s*=|javascript\s*:|<\s*foreignObject|<\s*iframe|<!ENTITY/i', $svg)) {
            $fail('ملف SVG فيه كود مش مسموح بيه (سكريبت أو أحداث).');
        }
    }
}
