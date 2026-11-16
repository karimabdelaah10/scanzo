<?php

namespace App\Support\Import;

use App\Exceptions\DomainException;
use Throwable;

/**
 * A catalogue file → rows keyed by what each column means.
 *
 * .xlsx is the main format and needs phpoffice/phpspreadsheet. It is written
 * against that package but the package is optional: without it an .xlsx upload
 * gets DomainException::importLibraryMissing (with the command to run), the
 * same pattern as label printing's missing-dependency page.
 *
 * CSV is read here with no package, and it is the part that needs care:
 * Excel on Windows saves "CSV" in the system code page (Windows-1256 for
 * Arabic), not UTF-8, and the result is broken names. The bytes are decoded by
 * what they actually are, never assumed.
 *
 * Row numbers returned are the spreadsheet's own (the heading is row 1), so
 * "row 47" in an error is the row the owner sees in Excel.
 */
class CatalogueFileReader
{
    /** Why 1000: see ImportCatalogue — it is a measured synchronous budget. */
    public const MAX_ROWS = 1000;

    /** Every column the file may have, and the headings that mean it. */
    public const COLUMNS = [
        'name' => ['اسم المنتج', 'المنتج', 'الاسم', 'name', 'product', 'product name'],
        'category' => ['التصنيف', 'الفئة', 'القسم', 'category'],
        'size' => ['المقاس', 'size'],
        'color' => ['اللون', 'color', 'colour'],
        'price' => ['السعر', 'سعر البيع', 'price'],
        'cost' => ['التكلفة', 'سعر التكلفة', 'cost'],
        'barcode' => ['الباركود', 'barcode'],
        'opening_stock' => ['الرصيد الافتتاحي', 'الرصيد', 'الكمية', 'opening stock', 'opening_stock', 'stock', 'quantity', 'qty'],
        'branch' => ['الفرع', 'branch'],
    ];

    public const REQUIRED = ['name', 'price'];

    /** What a missing required column is called when we tell the owner. */
    public const LABELS = [
        'name' => 'اسم المنتج',
        'category' => 'التصنيف',
        'size' => 'المقاس',
        'color' => 'اللون',
        'price' => 'السعر',
        'cost' => 'التكلفة',
        'barcode' => 'الباركود',
        'opening_stock' => 'الرصيد الافتتاحي',
        'branch' => 'الفرع',
    ];

    public static function xlsxAvailable(): bool
    {
        return class_exists(\PhpOffice\PhpSpreadsheet\Reader\Xlsx::class);
    }

    /**
     * @return array{rows: array<int, array{line: int, values: array<string, ?string>}>, ignored: array<int, string>}
     */
    public function read(string $path): array
    {
        $head = (string) file_get_contents($path, false, null, 0, 8);

        // .xlsx is a zip.
        if (str_starts_with($head, 'PK')) {
            return $this->fromGrid($this->xlsxGrid($path));
        }

        // The old binary .xls (OLE) — not supported, and not text either.
        if (str_starts_with($head, "\xD0\xCF\x11\xE0")) {
            throw DomainException::importUnsupportedFile();
        }

        return $this->fromGrid($this->csvGrid((string) file_get_contents($path)));
    }

    /* ---------- xlsx ---------- */

    /** @return array<int, array<int, ?string>> keyed by the sheet's row number */
    protected function xlsxGrid(string $path): array
    {
        if (! self::xlsxAvailable()) {
            throw DomainException::importLibraryMissing();
        }

        try {
            $reader = new \PhpOffice\PhpSpreadsheet\Reader\Xlsx();
            $reader->setReadDataOnly(true);

            if (! $reader->canRead($path)) {
                throw DomainException::importUnreadable();
            }

            $sheet = $reader->load($path)->getSheet(0);
            $highest = $sheet->getHighestDataRow();

            // The heading decides how many columns are read.
            $width = 0;
            $heading = $sheet->rangeToArray('A1:AD1', null, true, false, false)[0] ?? [];

            foreach ($heading as $i => $cell) {
                if (self::cell($cell) !== null) {
                    $width = $i + 1;
                }
            }

            if ($width === 0) {
                throw DomainException::importEmpty();
            }

            $last = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($width);
            $grid = [];
            $filled = 0;
            $blankRun = 0;

            for ($row = 1; $row <= $highest; $row++) {
                $cells = array_map(
                    self::cell(...),
                    $sheet->rangeToArray("A{$row}:{$last}{$row}", null, true, false, false)[0] ?? [],
                );

                if ($row > 1 && array_filter($cells, fn ($c) => $c !== null) === []) {
                    // A sheet whose empty rows carry formatting reports a huge
                    // "highest row": stop once the data has clearly ended.
                    if (++$blankRun >= 200) {
                        break;
                    }

                    continue;
                }

                $blankRun = 0;
                $grid[$row] = $cells;

                if ($row > 1 && ++$filled > self::MAX_ROWS) {
                    throw DomainException::importTooLarge(self::MAX_ROWS);
                }
            }

            return $grid;
        } catch (DomainException $e) {
            throw $e;
        } catch (Throwable) {
            throw DomainException::importUnreadable();
        }
    }

    /* ---------- csv ---------- */

    /** @return array<int, array<int, ?string>> keyed by the record number, which is Excel's row */
    protected function csvGrid(string $bytes): array
    {
        if ($bytes === '' || str_contains(substr($bytes, 0, 4096), "\0") && ! $this->isUtf16($bytes)) {
            throw $bytes === '' ? DomainException::importEmpty() : DomainException::importUnsupportedFile();
        }

        $text = $this->toUtf8($bytes);
        $delimiter = $this->delimiter($text);

        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $text);
        rewind($stream);

        $grid = [];
        $row = 0;
        $filled = 0;

        // The empty escape character: PHP's default "\" would eat the
        // backslash in a name, and is deprecated as a default.
        while (($record = fgetcsv($stream, 0, $delimiter, '"', '')) !== false) {
            $row++;
            $cells = array_map(fn ($c) => self::cell($c), $record);

            if (array_filter($cells, fn ($c) => $c !== null) === []) {
                continue;
            }

            $grid[$row] = $cells;

            if ($row > 1 && ++$filled > self::MAX_ROWS) {
                fclose($stream);
                throw DomainException::importTooLarge(self::MAX_ROWS);
            }
        }

        fclose($stream);

        return $grid;
    }

    protected function isUtf16(string $bytes): bool
    {
        return str_starts_with($bytes, "\xFF\xFE") || str_starts_with($bytes, "\xFE\xFF");
    }

    /**
     * By what the bytes are: a BOM says so, valid UTF-8 is UTF-8, and anything
     * else is what Excel wrote from an Arabic Windows — Windows-1256.
     */
    protected function toUtf8(string $bytes): string
    {
        if (str_starts_with($bytes, "\xEF\xBB\xBF")) {
            return substr($bytes, 3);
        }

        if (str_starts_with($bytes, "\xFF\xFE")) {
            return mb_convert_encoding(substr($bytes, 2), 'UTF-8', 'UTF-16LE');
        }

        if (str_starts_with($bytes, "\xFE\xFF")) {
            return mb_convert_encoding(substr($bytes, 2), 'UTF-8', 'UTF-16BE');
        }

        if (mb_check_encoding($bytes, 'UTF-8')) {
            return $bytes;
        }

        $converted = function_exists('iconv') ? @iconv('Windows-1256', 'UTF-8//IGNORE', $bytes) : false;

        return $converted !== false ? $converted : mb_convert_encoding($bytes, 'UTF-8', 'Windows-1256');
    }

    /** Excel in an Arabic locale uses ";" or a tab as often as ",". */
    protected function delimiter(string $text): string
    {
        $first = strtok($text, "\n") ?: '';
        $best = ',';
        $most = 0;

        foreach ([',', ';', "\t"] as $candidate) {
            $count = substr_count($first, $candidate);

            if ($count > $most) {
                $best = $candidate;
                $most = $count;
            }
        }

        return $best;
    }

    /* ---------- both ---------- */

    /**
     * @param  array<int, array<int, ?string>>  $grid
     * @return array{rows: array<int, array{line: int, values: array<string, ?string>}>, ignored: array<int, string>}
     */
    protected function fromGrid(array $grid): array
    {
        if ($grid === [] || ! isset($grid[1])) {
            throw DomainException::importEmpty();
        }

        $map = [];      // column index => key
        $ignored = [];

        foreach ($grid[1] as $index => $heading) {
            if ($heading === null) {
                continue;
            }

            $key = self::keyFor($heading);

            if ($key === null) {
                $ignored[] = $heading;

                continue;
            }

            if (in_array($key, $map, true)) {
                throw DomainException::importDuplicateColumn(self::LABELS[$key]);
            }

            $map[$index] = $key;
        }

        $missing = array_values(array_map(
            fn (string $key) => self::LABELS[$key],
            array_diff(self::REQUIRED, $map),
        ));

        if ($missing !== []) {
            throw DomainException::importMissingColumns($missing, array_values(array_filter($grid[1])));
        }

        $rows = [];

        foreach ($grid as $line => $cells) {
            if ($line === 1) {
                continue;
            }

            $values = array_fill_keys(array_keys(self::COLUMNS), null);

            foreach ($map as $index => $key) {
                $values[$key] = $cells[$index] ?? null;
            }

            $rows[] = ['line' => $line, 'values' => $values];
        }

        if ($rows === []) {
            throw DomainException::importEmpty();
        }

        return ['rows' => $rows, 'ignored' => $ignored];
    }

    /** Which column a heading means, tolerating case, spacing and alef spelling. */
    protected static function keyFor(string $heading): ?string
    {
        $wanted = self::headingForm($heading);

        foreach (self::COLUMNS as $key => $names) {
            foreach ($names as $name) {
                if (self::headingForm($name) === $wanted) {
                    return $key;
                }
            }
        }

        return null;
    }

    protected static function headingForm(string $text): string
    {
        $text = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $text)));
        $text = str_replace(['أ', 'إ', 'آ', 'ى', 'ـ'], ['ا', 'ا', 'ا', 'ي', ''], $text);

        return trim($text, " *:");
    }

    /**
     * One cell as text. xlsx hands back numbers as floats, and a barcode
     * stored as a number must not turn into 6.2212345E+12.
     */
    public static function cell(mixed $value): ?string
    {
        if ($value === null || is_bool($value)) {
            return null;
        }

        if (is_float($value) && floor($value) === $value && abs($value) < 1e15) {
            $value = (string) (int) $value;
        }

        // Non-breaking spaces, and the double spaces people leave in names.
        $text = trim((string) preg_replace('/[\s\x{00A0}]+/u', ' ', (string) $value));

        return $text === '' ? null : $text;
    }
}
