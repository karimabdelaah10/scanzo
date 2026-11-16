<?php

namespace App\Support\Labels;

/**
 * The physical shapes a shop can print on.
 *
 * Sizes are in millimetres because that is how label rolls and sticker sheets
 * are sold. The print stylesheet uses them directly, so what comes out of the
 * printer is the size written here and not a scaled approximation.
 */
enum LabelTemplate: string
{
    /** A roll on a Bluetooth or thermal label printer: one label per page. */
    case Roll40x30 = 'roll-40x30';
    case Roll50x30 = 'roll-50x30';

    /** A sheet of stickers through any ordinary printer. */
    case A4Grid24 = 'a4-24';

    public function label(): string
    {
        return match ($this) {
            self::Roll40x30 => 'رول 40×30 مم',
            self::Roll50x30 => 'رول 50×30 مم',
            self::A4Grid24 => 'ورقة A4 — 24 استيكر',
        };
    }

    /** Page size in mm. */
    public function page(): array
    {
        return match ($this) {
            self::Roll40x30 => ['width' => 40, 'height' => 30],
            self::Roll50x30 => ['width' => 50, 'height' => 30],
            self::A4Grid24 => ['width' => 210, 'height' => 297],
        };
    }

    /** One label's box in mm, and how many fit across and down a page. */
    public function cell(): array
    {
        return match ($this) {
            self::Roll40x30 => ['width' => 40, 'height' => 30, 'columns' => 1, 'rows' => 1, 'margin' => 0, 'gap_x' => 0, 'gap_y' => 0],
            self::Roll50x30 => ['width' => 50, 'height' => 30, 'columns' => 1, 'rows' => 1, 'margin' => 0, 'gap_x' => 0, 'gap_y' => 0],
            // 3 × 65 = 195 across 210, 8 × 35 = 280 across 297. The gaps are 0
            // for this sheet; the print page and the PDF both honour them.
            self::A4Grid24 => ['width' => 65, 'height' => 35, 'columns' => 3, 'rows' => 8, 'margin' => 7.5, 'gap_x' => 0, 'gap_y' => 0],
        };
    }

    public function perPage(): int
    {
        $cell = $this->cell();

        return $cell['columns'] * $cell['rows'];
    }

    /** A 1D barcode needs width to stay scannable; the smallest roll has none. */
    public function fitsBarcode(): bool
    {
        return $this !== self::Roll40x30;
    }

    public function isRoll(): bool
    {
        return $this->perPage() === 1;
    }
}
