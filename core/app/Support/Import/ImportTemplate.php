<?php

namespace App\Support\Import;

use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The file a shop downloads, fills in and uploads. Without it the shop guesses
 * the columns and gets them wrong.
 *
 * The example rows are rejected on upload (see ImportCatalogue), so a shop that
 * forgets to delete them is told, instead of getting a "قميص" it never sold.
 * Two rows rather than one: the thing a shop gets wrong is that a product with
 * several sizes is several rows with the same name.
 */
class ImportTemplate
{
    public const EXAMPLE_NAME = 'مثال (امسح السطرين دول)';

    public const HEADINGS = ['اسم المنتج', 'التصنيف', 'المقاس', 'اللون', 'السعر', 'التكلفة', 'الباركود', 'الرصيد الافتتاحي', 'الفرع'];

    public const EXAMPLES = [
        [self::EXAMPLE_NAME, 'رجالي', 'M', 'أزرق', '450', '260', '', '10', ''],
        [self::EXAMPLE_NAME, 'رجالي', 'L', 'أزرق', '450', '260', '', '5', ''],
    ];

    public const NOTES = [
        'اسم المنتج والسعر مطلوبين، والباقي اختياري.',
        'كل سطر = مقاس ولون واحد. الأسطر اللي ليها نفس اسم المنتج بتتجمع في منتج واحد.',
        'الأكواد (SKU) بتتعمل أوتوماتيك، متكتبهاش.',
        'الباركود: سيبه فاضي لو القطعة ملهاش باركود من المصنع.',
        'الرصيد الافتتاحي: عدد القطع اللي عندك دلوقتي. بيتسجّل كاستلام في الفرع المكتوب، أو الفرع اللي اخترته وقت الرفع لو الخانة فاضية.',
        'منتج اسمه موجود قبل كده بيترفض. لو في خطأ في سطر واحد، الملف كله مش بيتسجّل.',
        'اتأكد إن اسم التصنيف مكتوب بنفس الطريقة في كل الأسطر.',
    ];

    public static function xlsx(): StreamedResponse
    {
        return response()->streamDownload(function () {
            $book = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
            $sheet = $book->getActiveSheet();
            $sheet->setTitle('المنتجات');
            $sheet->setRightToLeft(true);

            $sheet->fromArray(self::HEADINGS, null, 'A1');
            $sheet->fromArray(self::EXAMPLES, null, 'A2', true);

            $sheet->getStyle('A1:I1')->getFont()->setBold(true);

            // Text, so Excel does not turn a barcode into 6.22E+12.
            $sheet->getStyle('G2:G1001')->getNumberFormat()->setFormatCode('@');

            foreach (range('A', 'I') as $column) {
                $sheet->getColumnDimension($column)->setWidth($column === 'A' ? 30 : 16);
            }

            $notes = $book->createSheet();
            $notes->setTitle('تعليمات');
            $notes->setRightToLeft(true);
            $notes->fromArray(array_map(fn ($line) => [$line], self::NOTES), null, 'A1');
            $notes->getColumnDimension('A')->setWidth(110);

            $book->setActiveSheetIndex(0);

            (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save('php://output');
        }, 'scanzo-products-template.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /** UTF-8 with a BOM: that is what makes Excel show Arabic correctly. */
    public static function csv(): StreamedResponse
    {
        return response()->streamDownload(function () {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, self::HEADINGS, ',', '"', '');

            foreach (self::EXAMPLES as $row) {
                fputcsv($out, $row, ',', '"', '');
            }

            fclose($out);
        }, 'scanzo-products-template.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
