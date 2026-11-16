<?php

namespace App\Http\Controllers;

use App\Actions\Labels\BuildLabelSheet;
use App\Exceptions\DomainException;
use App\Http\Requests\PrintLabelsRequest;
use App\Models\ProductVariant;
use App\Support\Labels\CodeImage;
use App\Support\Labels\LabelPdf;
use App\Support\Labels\LabelTemplate;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Labels, in three forms that all come down to the same millimetre sizes:
 * a PDF (the base: it downloads, shares and goes to a print shop), the same
 * labels as a page the browser prints, and a calibration page.
 *
 * Not Web Bluetooth: it works in one browser, over HTTPS, and each label
 * printer speaks its own protocol. The PDF is the interface; the printer's own
 * app takes it from the share sheet.
 */
class LabelController extends Controller
{
    /** The page the browser prints, for a computer with a printer attached. */
    public function print(PrintLabelsRequest $request, BuildLabelSheet $build): View
    {
        $this->authorizeCatalogue();

        if (! CodeImage::available()) {
            return view('labels.missing-dependency', [
                'title' => 'Label printing needs two packages',
                'command' => 'composer require bacon/bacon-qr-code picqer/php-barcode-generator',
                'why' => 'bacon/bacon-qr-code draws the QR as SVG, and picqer/php-barcode-generator draws the Code 128 for shops that later buy a USB scanner.',
            ]);
        }

        $job = $this->prepare($request, $build);

        if ($job instanceof View) {
            return $job;
        }

        ['template' => $template, 'rows' => $rows, 'showPrice' => $showPrice, 'showBarcode' => $showBarcode] = $job;

        $labels = $this->expand($rows, fn (ProductVariant $variant) => [
            'qr' => CodeImage::qr($variant->sku, $template->isRoll() ? 110 : 90),
            'barcode' => $showBarcode
                ? CodeImage::barcode($variant->barcode ?: $variant->sku, 150, 28)
                : null,
        ]);

        return view('labels.sheet', [
            'template' => $template,
            'labels' => $labels,
            'showPrice' => $showPrice,
            'showBarcode' => $showBarcode,
            'business' => $request->user()->business,
            'calibrationUrl' => route('labels.calibration', ['template' => $template->value]),
            'summary' => $rows->map(fn (array $row) => [
                'sku' => $row['variant']->sku,
                'name' => $row['variant']->product->name.' · '.$row['variant']->label(),
                'copies' => $row['copies'],
            ]),
        ]);
    }

    /** The PDF: pages in millimetres. `download=1` saves it instead of showing it. */
    public function pdf(PrintLabelsRequest $request, BuildLabelSheet $build, LabelPdf $pdf): View|Response
    {
        $this->authorizeCatalogue();

        if (! $pdf->available()) {
            return $this->missingPdf();
        }

        $job = $this->prepare($request, $build);

        if ($job instanceof View) {
            return $job;
        }

        $labels = $this->expand($job['rows'], fn (ProductVariant $variant) => [
            'barcode_value' => (string) ($variant->barcode ?: $variant->sku),
        ]);

        return $this->pdfResponse(
            $pdf->labels($job['template'], $labels, $job['showPrice'], $job['showBarcode']),
            'labels-'.$job['template']->value.'.pdf',
            $request->boolean('download'),
        );
    }

    /** A page with a 50 mm ruler: print it, measure it, and the printer's scaling shows. */
    public function calibration(Request $request): View
    {
        $this->authorizeCatalogue();

        $template = $this->templateFrom($request);

        return view('labels.calibration', [
            'template' => $template,
            'page' => $template->page(),
            'pdfUrl' => route('labels.calibration.pdf', ['template' => $template->value, 'download' => 1]),
        ]);
    }

    public function calibrationPdf(Request $request, LabelPdf $pdf): View|Response
    {
        $this->authorizeCatalogue();

        $template = $this->templateFrom($request);

        if (! $pdf->available()) {
            return $this->missingPdf();
        }

        return $this->pdfResponse(
            $pdf->calibration($template),
            'calibration-'.$template->value.'.pdf',
            $request->boolean('download'),
        );
    }

    protected function templateFrom(Request $request): LabelTemplate
    {
        $request->validate(['template' => ['nullable', Rule::enum(LabelTemplate::class)]]);

        return LabelTemplate::from($request->input('template', LabelTemplate::Roll40x30->value));
    }

    protected function authorizeCatalogue(): void
    {
        if (Gate::denies('manage-catalogue')) {
            throw DomainException::notAllowedToManageCatalogue();
        }
    }

    protected function missingPdf(): Response
    {
        return response()->view('labels.missing-dependency', [
            'title' => 'PDF labels need one package',
            'command' => LabelPdf::installCommand(),
            'why' => 'tecnickcom/tcpdf writes the PDF with pages sized in millimetres, shapes Arabic and draws the QR itself. It is pure PHP, so there is no extension to compile.',
        ], 503);
    }

    protected function pdfResponse(string $bytes, string $name, bool $download): Response
    {
        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => ($download ? 'attachment' : 'inline').'; filename="'.$name.'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * What every form shares: what to print, how many of each, on what.
     * Returns a page instead when there is nothing to print: these are opened
     * in a tab, so a failure has to be a page too, not the JSON a
     * DomainException renders.
     *
     * @return array{template: LabelTemplate, rows: Collection, showPrice: bool, showBarcode: bool}|View
     */
    protected function prepare(PrintLabelsRequest $request, BuildLabelSheet $build): array|View
    {
        $template = LabelTemplate::from($request->input('template', LabelTemplate::Roll40x30->value));
        $branchId = $request->input('branch_id');

        if ($branchId !== null && ! $request->user()->canAccessBranch($branchId)) {
            throw DomainException::branchNotAllowed();
        }

        try {
            $rows = $build->handle(
                variantIds: $request->input('variant_ids', []),
                productId: $request->input('product_id'),
                branchId: $branchId,
                copies: $request->input('copies', 'stock'),
                fixedCopies: $request->integer('count') ?: null,
            );
        } catch (DomainException $e) {
            return view('labels.empty', [
                'message' => $this->messageFor($e),
                'back' => url()->previous('/'),
            ]);
        }

        return [
            'template' => $template,
            'rows' => $rows,
            'showPrice' => $request->boolean('show_price', true),
            'showBarcode' => $request->boolean('show_barcode', $template->fitsBarcode()) && $template->fitsBarcode(),
        ];
    }

    /**
     * One entry per sticker, so a variant with 12 pieces becomes 12 labels.
     * `$extra` adds the part that differs by form (SVG for the page, the raw
     * value for the PDF), computed once per variant and not once per copy.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function expand(Collection $rows, callable $extra): array
    {
        $labels = [];

        foreach ($rows as $row) {
            /** @var ProductVariant $variant */
            $variant = $row['variant'];

            $rendered = [
                'sku' => $variant->sku,
                'product_name' => $variant->product->name,
                'label' => $variant->label(),
                'price' => (string) $variant->price,
            ] + $extra($variant);

            for ($copy = 0; $copy < $row['copies']; $copy++) {
                $labels[] = $rendered;
            }
        }

        return $labels;
    }

    /** The message in Arabic for the failures this page can hit. */
    protected function messageFor(DomainException $e): string
    {
        return match ($e->errorCode) {
            'nothing_to_print' => 'مفيش ملصقات تتطبع: يا إما مفيش حاجة اتحددت، يا إما اللي اتحدد رصيده صفر.',
            'too_many_labels' => 'عدد الملصقات أكبر من الحد المسموح للطبعة الواحدة. قلّل العدد وجرّب تاني.',
            default => $e->getMessage(),
        };
    }
}
