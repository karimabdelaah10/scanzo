<?php

namespace Tests\Feature;

use App\Actions\Labels\BuildLabelSheet;
use App\Exceptions\DomainException;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Support\BusinessContext;
use App\Support\Labels\LabelPdf;
use App\Support\Labels\LabelTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LabelTest extends TestCase
{
    use RefreshDatabase;

    public function test_one_label_is_printed_for_every_piece_on_the_shelf(): void
    {
        $this->tenant();
        [$branch] = $this->shopFloor();

        $many = ProductVariant::factory()->create(['sku' => 'SKU-40001']);
        $few = ProductVariant::factory()->create(['sku' => 'SKU-40002']);

        $this->giveStock($many, $branch, 9);
        $this->giveStock($few, $branch, 2);

        $rows = app(BuildLabelSheet::class)->handle(
            variantIds: [$many->id, $few->id],
            branchId: $branch->id,
            copies: 'stock',
        );

        $this->assertSame(9, $rows->firstWhere('variant.sku', 'SKU-40001')['copies']);
        $this->assertSame(2, $rows->firstWhere('variant.sku', 'SKU-40002')['copies']);
        $this->assertSame(11, $rows->sum('copies'));
    }

    public function test_an_item_with_nothing_on_the_shelf_gets_no_label(): void
    {
        $this->tenant();
        [$branch] = $this->shopFloor();

        $stocked = ProductVariant::factory()->create();
        $empty = ProductVariant::factory()->create();
        $this->giveStock($stocked, $branch, 3);

        $rows = app(BuildLabelSheet::class)->handle(
            variantIds: [$stocked->id, $empty->id],
            branchId: $branch->id,
        );

        $this->assertCount(1, $rows);
        $this->assertSame($stocked->id, $rows->first()['variant']->id);
    }

    public function test_a_shelf_that_went_negative_prints_nothing_rather_than_nonsense(): void
    {
        $this->tenant();
        [$branch, $device, $seller] = $this->shopFloor();

        $variant = ProductVariant::factory()->create();
        $this->giveStock($variant, $branch, 1);
        $this->sell($seller, $device, [['variant_id' => $variant->id, 'quantity' => 1]]);
        // A second till's offline sale lands late and takes it below zero.
        app(\App\Services\StockLedger::class)->post(
            variant: $variant,
            branchId: $branch->id,
            quantity: -1,
            type: \App\Enums\StockMovementType::Sale,
        );

        $this->expectException(DomainException::class);

        app(BuildLabelSheet::class)->handle(
            variantIds: [$variant->id],
            branchId: $branch->id,
        );
    }

    public function test_a_product_prints_every_size_and_colour_it_has(): void
    {
        $this->tenant();
        [$branch] = $this->shopFloor();

        $product = Product::factory()->create(['name' => 'قميص']);
        $other = Product::factory()->create(['name' => 'بنطلون']);

        foreach (['S', 'M', 'L'] as $size) {
            $variant = ProductVariant::factory()->create(['product_id' => $product->id, 'size' => $size]);
            $this->giveStock($variant, $branch, 2);
        }

        $otherVariant = ProductVariant::factory()->create(['product_id' => $other->id]);
        $this->giveStock($otherVariant, $branch, 5);

        $rows = app(BuildLabelSheet::class)->handle(
            productId: $product->id,
            branchId: $branch->id,
        );

        $this->assertCount(3, $rows);
        $this->assertSame(6, $rows->sum('copies'));
    }

    public function test_a_fixed_count_prints_that_many_of_each(): void
    {
        $this->tenant();

        $a = ProductVariant::factory()->create();
        $b = ProductVariant::factory()->create();

        $rows = app(BuildLabelSheet::class)->handle(
            variantIds: [$a->id, $b->id],
            copies: 'fixed',
            fixedCopies: 4,
        );

        $this->assertSame(8, $rows->sum('copies'));

        // And one each, for replacing a sticker that fell off.
        $single = app(BuildLabelSheet::class)->handle(
            variantIds: [$a->id, $b->id],
            copies: 'one',
        );

        $this->assertSame(2, $single->sum('copies'));
    }

    public function test_an_unreasonable_number_of_labels_is_refused(): void
    {
        $this->tenant();
        [$branch] = $this->shopFloor();

        $variant = ProductVariant::factory()->create();
        $this->giveStock($variant, $branch, BuildLabelSheet::MAX_LABELS + 1);

        try {
            app(BuildLabelSheet::class)->handle(
                variantIds: [$variant->id],
                branchId: $branch->id,
            );
            $this->fail('A roll-sized print run should have been refused.');
        } catch (DomainException $e) {
            $this->assertSame('too_many_labels', $e->errorCode);
            $this->assertSame(BuildLabelSheet::MAX_LABELS, $e->context['limit']);
        }
    }

    public function test_nothing_selected_is_refused(): void
    {
        $this->tenant();

        $this->expectException(DomainException::class);

        app(BuildLabelSheet::class)->handle();
    }

    public function test_a_variant_from_another_business_is_not_printed(): void
    {
        $this->tenant();
        $otherVariant = ProductVariant::factory()->create();

        $this->tenant();
        [$branch] = $this->shopFloor();
        $mine = ProductVariant::factory()->create();
        $this->giveStock($mine, $branch, 3);

        $rows = app(BuildLabelSheet::class)->handle(
            variantIds: [$mine->id, $otherVariant->id],
            branchId: $branch->id,
        );

        $this->assertCount(1, $rows);
        $this->assertSame($mine->id, $rows->first()['variant']->id);
    }

    public function test_a_seller_cannot_print_labels(): void
    {
        $this->tenant();
        [$branch, , $seller] = $this->shopFloor();

        $variant = ProductVariant::factory()->create();
        $this->giveStock($variant, $branch, 2);

        $this->actingAs($seller)
            ->get('/labels/print?branch_id='.$branch->id.'&variant_ids[]='.$variant->id)
            ->assertForbidden();
    }

    public function test_counting_by_stock_has_to_say_which_branch(): void
    {
        $this->tenant();
        $owner = User::factory()->owner()->create();
        $variant = ProductVariant::factory()->create();

        $this->actingAs($owner)
            ->get('/labels/print?copies=stock&variant_ids[]='.$variant->id)
            ->assertSessionHasErrors('branch_id');
    }

    public function test_every_template_fits_on_its_paper(): void
    {
        foreach (LabelTemplate::cases() as $template) {
            $page = $template->page();
            $cell = $template->cell();

            $usedWidth = $cell['columns'] * $cell['width'] + ($cell['columns'] - 1) * $cell['gap_x'] + 2 * $cell['margin'];
            $usedHeight = $cell['rows'] * $cell['height'] + ($cell['rows'] - 1) * $cell['gap_y'] + 2 * $cell['margin'];

            $this->assertLessThanOrEqual($page['width'], $usedWidth, $template->value.' is too wide for its page');
            $this->assertLessThanOrEqual($page['height'], $usedHeight, $template->value.' is too tall for its page');
        }
    }

    public function test_twelve_pieces_make_twelve_labels_on_the_print_page(): void
    {
        $this->tenant();
        [$branch] = $this->shopFloor();
        $owner = User::factory()->owner()->create();

        $variant = ProductVariant::factory()->create();
        $this->giveStock($variant, $branch, 12);

        $html = $this->actingAs($owner)
            ->get('/labels/print?branch_id='.$branch->id.'&variant_ids[]='.$variant->id)
            ->assertOk()
            ->getContent();

        $this->assertSame(12, substr_count($html, 'class="label"'));
    }

    public function test_another_shops_variant_never_reaches_the_labels(): void
    {
        $this->tenant();
        $theirs = ProductVariant::factory()->create(['sku' => 'SKU-THEIRS']);

        $this->tenant();
        [$branch] = $this->shopFloor();
        $owner = User::factory()->owner()->create();
        $mine = ProductVariant::factory()->create(['sku' => 'SKU-MINE']);
        $this->giveStock($mine, $branch, 2);

        $query = '?copies=one&variant_ids[]='.$mine->id.'&variant_ids[]='.$theirs->id;

        $html = $this->actingAs($owner)->get('/labels/print'.$query)->assertOk()->getContent();
        $this->assertSame(1, substr_count($html, 'class="label"'));
        $this->assertStringNotContainsString('SKU-THEIRS', $html);

        $this->requirePdfLibrary();

        $pdf = $this->actingAs($owner)->get('/labels/pdf'.$query)->assertOk()->getContent();
        $this->assertSame(1, $this->pageCount($pdf));
    }

    public function test_the_pdf_page_is_the_paper_size_in_millimetres(): void
    {
        $this->requirePdfLibrary();

        $labels = [[
            'sku' => 'SKU-1', 'product_name' => 'قميص', 'label' => 'M', 'price' => '100', 'barcode_value' => 'SKU-1',
        ]];

        foreach (LabelTemplate::cases() as $template) {
            $pdf = app(LabelPdf::class)->labels($template, $labels, true, $template->fitsBarcode());
            $page = $template->page();

            [$w, $h] = $this->mediaBox($pdf);

            // 1 pt = 25.4/72 mm; TCPDF writes the box to three decimals.
            $this->assertEqualsWithDelta($page['width'], $w * 25.4 / 72, 0.01, $template->value.' width');
            $this->assertEqualsWithDelta($page['height'], $h * 25.4 / 72, 0.01, $template->value.' height');
        }
    }

    public function test_a_roll_pdf_has_one_page_per_label_and_a_sheet_fills_pages(): void
    {
        $this->requirePdfLibrary();

        $label = ['sku' => 'SKU-1', 'product_name' => 'قميص', 'label' => 'M', 'price' => '100', 'barcode_value' => 'SKU-1'];
        $pdf = app(LabelPdf::class);

        $this->assertSame(12, $this->pageCount($pdf->labels(LabelTemplate::Roll40x30, array_fill(0, 12, $label), true, false)));
        // 24 fit on one A4 sheet; the 25th starts the next.
        $this->assertSame(1, $this->pageCount($pdf->labels(LabelTemplate::A4Grid24, array_fill(0, 24, $label), true, true)));
        $this->assertSame(2, $this->pageCount($pdf->labels(LabelTemplate::A4Grid24, array_fill(0, 25, $label), true, true)));
    }

    public function test_an_arabic_product_name_is_really_drawn_in_the_pdf(): void
    {
        $this->requirePdfLibrary();

        $label = ['sku' => 'SKU-1', 'product_name' => 'قميص', 'label' => null, 'price' => '100', 'barcode_value' => 'SKU-1'];
        $pdf = app(LabelPdf::class)->labels(LabelTemplate::Roll40x30, [$label], false, false);

        // A page count passes even if every letter came out as a box. What
        // proves the letters are there is the text the page itself draws.
        $this->assertMatchesRegularExpression('/BaseFont\s*\/\w*\+?DejaVuSans/i', $pdf, 'not drawn in an Arabic-capable font');

        $arabic = array_filter(
            $this->drawnCodePoints($pdf),
            fn (int $cp) => ($cp >= 0x0600 && $cp <= 0x06FF) || ($cp >= 0xFB50 && $cp <= 0xFDFF) || ($cp >= 0xFE70 && $cp <= 0xFEFF),
        );

        // قميص is four letters. Shaping picks the joined forms, so compare the
        // count, not the characters.
        $this->assertCount(4, $arabic, 'the Arabic letters are not in the PDF');
    }

    public function test_the_pdf_route_serves_twelve_pages_for_twelve_pieces(): void
    {
        $this->requirePdfLibrary();

        $this->tenant();
        [$branch] = $this->shopFloor();
        $owner = User::factory()->owner()->create();
        $variant = ProductVariant::factory()->create();
        $this->giveStock($variant, $branch, 12);

        $response = $this->actingAs($owner)
            ->get('/labels/pdf?download=1&template=roll-40x30&branch_id='.$branch->id.'&variant_ids[]='.$variant->id)
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        $this->assertStringContainsString('attachment', $response->headers->get('Content-Disposition'));
        $this->assertSame(12, $this->pageCount($response->getContent()));
    }

    public function test_without_the_pdf_library_the_page_says_which_command_to_run(): void
    {
        $this->tenant();
        $owner = User::factory()->owner()->create();

        $this->app->instance(LabelPdf::class, new class extends LabelPdf {
            public function available(): bool
            {
                return false;
            }
        });

        foreach (['/labels/pdf?copies=one&variant_ids[]='.fake()->uuid(), '/labels/calibration.pdf'] as $url) {
            $this->actingAs($owner)->get($url)
                ->assertStatus(503)
                ->assertSee('composer require tecnickcom/tcpdf');
        }
    }

    public function test_a_library_without_its_font_is_unavailable_and_answers_503_not_500(): void
    {
        $this->tenant();
        $owner = User::factory()->owner()->create();

        // The class is loaded (it is installed) but the font file is not there:
        // exactly the TCPDF 7 situation.
        $this->app->instance(LabelPdf::class, new class extends LabelPdf {
            protected function fontFile(): string
            {
                return '/nonexistent/dejavusans.php';
            }
        });

        $this->assertTrue(class_exists(\TCPDF::class));
        $this->assertFalse(app(LabelPdf::class)->available());

        foreach (['/labels/pdf?copies=one&variant_ids[]='.fake()->uuid(), '/labels/calibration.pdf'] as $url) {
            $this->actingAs($owner)->get($url)
                ->assertStatus(503)
                ->assertSee('composer require tecnickcom/tcpdf');
        }
    }

    public function test_the_calibration_page_carries_a_fifty_millimetre_ruler(): void
    {
        $this->tenant();
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)->get('/labels/calibration?template=roll-50x30')
            ->assertOk()
            ->assertSee('width: 50mm', false)
            ->assertSee('size: 50mm 30mm', false)
            ->assertSee('50 mm');

        $this->actingAs($owner)->get('/labels/calibration?template=nonsense')->assertSessionHasErrors('template');
    }

    public function test_a_seller_cannot_reach_the_pdf_or_the_calibration_page(): void
    {
        $this->tenant();
        [, , $seller] = $this->shopFloor();

        $this->actingAs($seller)->get('/labels/pdf?copies=one')->assertForbidden();
        $this->actingAs($seller)->get('/labels/calibration')->assertForbidden();
        $this->actingAs($seller)->get('/labels/calibration.pdf')->assertForbidden();
    }

    protected function requirePdfLibrary(): void
    {
        if (! app(LabelPdf::class)->available()) {
            $this->markTestSkipped('Needs tecnickcom/tcpdf: composer require tecnickcom/tcpdf');
        }
    }

    /** @return array{0: float, 1: float} first page's width and height, in points */
    protected function mediaBox(string $pdf): array
    {
        $this->assertSame(1, preg_match('/\/MediaBox\s*\[\s*0(?:\.0+)?\s+0(?:\.0+)?\s+([\d.]+)\s+([\d.]+)\s*\]/', $pdf, $m));

        return [(float) $m[1], (float) $m[2]];
    }

    /**
     * @return int[] the Unicode code points the pages draw with `TJ`. TCPDF's
     *               Unicode fonts use Identity-H, so each character is two
     *               bytes, UTF-16BE, inside the PDF string.
     */
    protected function drawnCodePoints(string $pdf): array
    {
        $found = [];
        preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $streams);

        foreach ($streams[1] as $raw) {
            $data = @gzuncompress($raw);

            if ($data === false || ! preg_match_all('/\[\((.*?)\)\]\s*TJ/s', $data, $m)) {
                continue;
            }

            foreach ($m[1] as $string) {
                // Undo the PDF string escapes: \( \) \\ and \ddd octal.
                $bytes = preg_replace_callback('/\\\\(?:([0-7]{1,3})|(.))/s', fn ($e) => $e[1] !== ''
                    ? chr(octdec($e[1]) & 0xFF)
                    : (['n' => "\n", 'r' => "\r", 't' => "\t", 'b' => "\x08", 'f' => "\x0C"][$e[2]] ?? $e[2]), $string);

                foreach (mb_str_split(mb_convert_encoding($bytes, 'UTF-8', 'UTF-16BE')) as $char) {
                    $found[] = mb_ord($char);
                }
            }
        }

        return $found;
    }

    protected function pageCount(string $pdf): int
    {
        return preg_match_all('/\/Type\s*\/Page(?![s\w])/', $pdf);
    }

    protected function tearDown(): void
    {
        app(BusinessContext::class)->forgetBusiness();

        parent::tearDown();
    }
}
