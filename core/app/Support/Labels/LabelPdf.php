<?php

namespace App\Support\Labels;

use RuntimeException;

/**
 * Labels as a PDF whose pages are the paper, in millimetres.
 *
 * TCPDF was chosen because its unit is the millimetre and its page size is
 * whatever array of millimetres it is given: no CSS engine between the number
 * and the page, so nothing rounds or rescales it. It also shapes Arabic itself
 * with its bundled DejaVu font, and draws QR and Code 128 natively, so the
 * PDF needs neither the SVG generators nor a browser.
 *
 * The file also asks the viewer not to scale when printing. Printers that
 * scale anyway are what the calibration page is for.
 *
 * Needs the 6.x line: 7.x is a rewrite on tc-lib-pdf that ships no fonts at
 * all. Not installed by default: `composer require tecnickcom/tcpdf:^6.6`.
 */
class LabelPdf
{
    protected const FONT = 'dejavusans';

    /**
     * True only if a PDF can really be produced: the class is there AND the
     * font the labels are drawn in is. The class alone is not enough: a
     * library without its fonts loads fine and then fails on the first line
     * of text, which is a 500 instead of a page saying what to run.
     */
    public function available(): bool
    {
        return class_exists(\TCPDF::class) && is_file($this->fontFile());
    }

    /** The TCPDF definition file for FONT. Overridable so the missing-font case can be tested. */
    protected function fontFile(): string
    {
        return (defined('K_PATH_FONTS') ? K_PATH_FONTS : '').self::FONT.'.php';
    }

    public static function installCommand(): string
    {
        return 'composer require tecnickcom/tcpdf:^6.6';
    }

    /**
     * @param  array<int, array{sku: string, product_name: string, label: ?string, price: string, barcode_value: string}>  $labels
     */
    public function labels(LabelTemplate $template, array $labels, bool $showPrice, bool $showBarcode): string
    {
        $page = $template->page();
        $cell = $template->cell();
        $perPage = $template->perPage();

        $pdf = $this->document($page['width'], $page['height'], 'Labels');

        foreach ($labels as $i => $item) {
            if ($i % $perPage === 0) {
                $pdf->AddPage();
            }

            $slot = $i % $perPage;
            $col = $slot % $cell['columns'];
            $row = intdiv($slot, $cell['columns']);

            // Columns count from the right, as the print page's RTL flow does.
            $x = $page['width'] - $cell['margin'] - ($col + 1) * $cell['width'] - $col * $cell['gap_x'];
            $y = $cell['margin'] + $row * ($cell['height'] + $cell['gap_y']);

            $this->drawLabel($pdf, $item, $x, $y, $cell['width'], $cell['height'], $template->isRoll(), $showPrice, $showBarcode);
        }

        return $pdf->Output('labels.pdf', 'S');
    }

    /** One page, the size of the template's page, with a 50 mm ruler to measure. */
    public function calibration(LabelTemplate $template): string
    {
        $page = $template->page();
        $pdf = $this->document($page['width'], $page['height'], 'Calibration');
        $pdf->AddPage();

        $x = ($page['width'] - 50) / 2;
        $y = $page['height'] / 2;

        $pdf->SetLineWidth(0.2);
        $pdf->Line($x, $y, $x + 50, $y);

        // A tick every millimetre, longer every 5 and 10, so a ruler laid on
        // the paper shows where it drifts.
        for ($mm = 0; $mm <= 50; $mm++) {
            $len = $mm % 10 === 0 ? 3.0 : ($mm % 5 === 0 ? 2.0 : 1.0);
            $pdf->Line($x + $mm, $y - $len, $x + $mm, $y);
        }

        $pdf->SetFont(self::FONT, 'B', 7);
        $pdf->SetXY($x, $y + 1);
        $pdf->Cell(50, 3, '50 mm', 0, 0, 'C');

        return $pdf->Output('calibration.pdf', 'S');
    }

    protected function document(float $width, float $height, string $title): \TCPDF
    {
        if (! $this->available()) {
            throw new RuntimeException('PDF labels need TCPDF 6 with its fonts. Run: '.self::installCommand());
        }

        $pdf = new \TCPDF($width > $height ? 'L' : 'P', 'mm', [$width, $height], true, 'UTF-8', false);

        $pdf->SetCreator('Scanzo');
        $pdf->SetTitle($title);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(0, 0, 0);
        $pdf->SetAutoPageBreak(false, 0);
        $pdf->setCellPaddings(0, 0, 0, 0);
        $pdf->setCellMargins(0, 0, 0, 0);
        $pdf->setViewerPreferences(['PrintScaling' => 'None']);

        return $pdf;
    }

    protected function drawLabel(\TCPDF $pdf, array $item, float $x, float $y, float $w, float $h, bool $roll, bool $showPrice, bool $showBarcode): void
    {
        $pad = 1.5;
        $qr = $roll ? 15.0 : 13.0;

        // QR on the right, vertically centred; text to its left.
        $qrX = $x + $w - $pad - $qr;
        $pdf->write2DBarcode($item['sku'], 'QRCODE,M', $qrX, $y + ($h - $qr) / 2, $qr, $qr, [
            'border' => false, 'padding' => 0, 'fgcolor' => [0, 0, 0], 'bgcolor' => false,
        ], 'N');

        $textX = $x + $pad;
        $textW = $qrX - $pad - $textX;
        $cursor = $y + $pad;
        $bottom = $y + $h - $pad;

        $cursor = $this->line($pdf, $item['product_name'], 'B', $roll ? 2.6 : 2.8, $textX, $cursor, $textW, 'R', 2, $bottom);

        if (! empty($item['label'])) {
            $cursor = $this->line($pdf, $item['label'], 'B', 2.4, $textX, $cursor, $textW, 'R', 1, $bottom);
        }

        $cursor = $this->line($pdf, $item['sku'], '', 2.1, $textX, $cursor, $textW, 'L', 1, $bottom);

        if ($showPrice) {
            $cursor = $this->line($pdf, $item['price'].' ج.م', 'B', 3.6, $textX, $cursor + 0.3, $textW, 'R', 1, $bottom);
        }

        if ($showBarcode && $item['barcode_value'] !== '') {
            $barH = min(6.0, $bottom - $cursor - 0.4);

            if ($barH > 2) {
                $pdf->write1DBarcode($item['barcode_value'], 'C128', $textX, $cursor + 0.4, $textW, $barH, 0.4, [
                    'border' => false, 'padding' => 0, 'fgcolor' => [0, 0, 0], 'bgcolor' => false, 'text' => false,
                ], 'N');
            }
        }
    }

    /** Draws a line of text at a font size given in mm; returns the y below it. */
    protected function line(\TCPDF $pdf, string $text, string $style, float $sizeMm, float $x, float $y, float $w, string $align, int $maxLines, float $bottom): float
    {
        $lineH = $sizeMm * 1.15;
        $height = min($lineH * $maxLines, max(0, $bottom - $y));

        if ($height < $lineH * 0.9) {
            return $y;
        }

        $pdf->SetFont(self::FONT, $style, $sizeMm * 72 / 25.4);
        // fitcell shrinks the text rather than letting it spill past the box.
        $pdf->MultiCell($w, $height, $text, 0, $align, false, 1, $x, $y, true, 0, false, true, $height, 'T', true);

        return $y + $height + 0.3;
    }
}
