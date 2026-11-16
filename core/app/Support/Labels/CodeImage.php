<?php

namespace App\Support\Labels;

use RuntimeException;

/**
 * QR and 1D barcodes as inline SVG.
 *
 * SVG rather than PNG so the code stays crisp at any printer resolution and
 * needs no image extension compiled into PHP. Both libraries are pure PHP for
 * the same reason.
 *
 * picqer changed its API between major versions, so both shapes are supported
 * rather than pinning the install to one of them.
 */
class CodeImage
{
    /** A QR holding the item's code. The label carries a code, never a price. */
    public static function qr(string $value, int $size = 120): string
    {
        if (! class_exists(\BaconQrCode\Writer::class)) {
            throw new RuntimeException(self::missing());
        }

        $renderer = new \BaconQrCode\Renderer\ImageRenderer(
            new \BaconQrCode\Renderer\RendererStyle\RendererStyle($size, 0),
            new \BaconQrCode\Renderer\Image\SvgImageBackEnd()
        );

        return self::inline((new \BaconQrCode\Writer($renderer))->writeString($value));
    }

    /** Code 128, for the shops that buy a cheap USB scanner later. */
    public static function barcode(string $value, int $width = 160, int $height = 40): string
    {
        // picqer 3.x
        if (class_exists(\Picqer\Barcode\Renderers\SvgRenderer::class)) {
            $barcode = (new \Picqer\Barcode\Types\TypeCode128())->getBarcode($value);

            return self::inline((new \Picqer\Barcode\Renderers\SvgRenderer())->render($barcode, $width, $height));
        }

        // picqer 2.x
        if (class_exists(\Picqer\Barcode\BarcodeGeneratorSVG::class)) {
            $generator = new \Picqer\Barcode\BarcodeGeneratorSVG();

            return self::inline($generator->getBarcode(
                $value,
                \Picqer\Barcode\BarcodeGeneratorSVG::TYPE_CODE_128,
                2,
                $height
            ));
        }

        throw new RuntimeException(self::missing());
    }

    public static function available(): bool
    {
        return class_exists(\BaconQrCode\Writer::class);
    }

    /** Strip the XML prologue so the SVG can sit straight in the page. */
    protected static function inline(string $svg): string
    {
        return trim(preg_replace('/<\?xml[^>]*\?>/', '', $svg));
    }

    protected static function missing(): string
    {
        return 'Label printing needs its code generators. Run: '
            .'composer require bacon/bacon-qr-code picqer/php-barcode-generator';
    }
}
