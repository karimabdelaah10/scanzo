<?php

namespace App\Actions\Catalogue;

use App\Enums\StockMovementType;
use App\Exceptions\DomainException;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\StockLedger;
use App\Support\Import\CatalogueFileReader;
use App\Support\Import\ImportTemplate;
use App\Support\SkuGenerator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * A catalogue file → products, variants, categories and opening stock.
 *
 * All or nothing. The file is checked completely before the first row is
 * written, every problem is collected, and the write is one transaction. A
 * half-imported catalogue is worse than none: the owner cannot tell what went
 * in, re-uploads, and gets duplicates.
 *
 * A product whose name already exists is refused rather than merged, so a file
 * uploaded twice by mistake is rejected instead of doubling the catalogue, and
 * adding new pieces later works because their names are new.
 *
 * It runs inside the request, not a queue, hence the row cap
 * (CatalogueFileReader::MAX_ROWS = 1000). Measured, a 1000-row file is a few
 * seconds on the dev container, against PHP-FPM's 30 s ceiling; the cost is
 * about three queries per row. 1000 rows is also two to three hundred
 * products for a clothing shop, and a bigger one uploads in parts.
 *
 * Codes are generated here, in one run for the whole file; opening stock goes
 * through StockLedger as a purchase movement like any delivery.
 */
class ImportCatalogue
{
    /** At most this many errors are sent back; the count is always the real one. */
    public const SHOW_ERRORS = 200;

    public const OPENING_NOTE = 'رصيد افتتاحي (استيراد)';

    protected const MAX_PRICE = 9999999999;

    public function __construct(
        protected CreateProduct $createProduct,
        protected StockLedger $stock,
        protected CatalogueFileReader $reader,
    ) {}

    /**
     * @return array{products: int, variants: int, categories_created: array<int, string>, categories_existing: array<int, string>, similar_categories: array<int, array{name: string, like: string}>, stock_movements: int, stock_pieces: int, ignored_columns: array<int, string>}
     */
    public function handle(User $user, string $path, ?string $defaultBranchId = null): array
    {
        if (Gate::denies('manage-catalogue')) {
            throw DomainException::notAllowedToManageCatalogue();
        }

        if (Gate::denies('manage-stock')) {
            throw DomainException::notAllowedToManageStock();
        }

        // Two uploads at once would both pass the "name already exists" check.
        $lock = Cache::lock('catalogue-import:'.$user->business_id, 120);

        if (! $lock->get()) {
            throw DomainException::importInProgress();
        }

        try {
            $file = $this->reader->read($path);
            $plan = $this->check($user, $file['rows'], $defaultBranchId);

            if ($plan['errors'] !== []) {
                throw DomainException::importRejected($plan['errors'], count($file['rows']), self::SHOW_ERRORS);
            }

            return $this->write($user, $plan) + ['ignored_columns' => $file['ignored']];
        } finally {
            $lock->release();
        }
    }

    /* ---------- checking: reads, never writes ---------- */

    /**
     * @param  array<int, array{line: int, values: array<string, ?string>}>  $rows
     * @return array{errors: array<int, array{row: int, field: string, message: string}>, products: array<string, array>, newCategories: array<string, string>, existingCategories: array<string, string>, similar: array<int, array{name: string, like: string}>}
     */
    protected function check(User $user, array $rows, ?string $defaultBranchId): array
    {
        $errors = [];
        $fail = function (int $line, string $field, string $message) use (&$errors) {
            $errors[] = ['row' => $line, 'field' => $field, 'message' => $message];
        };

        $existingProducts = Product::withTrashed()->pluck('name')
            ->mapWithKeys(fn ($name) => [self::key($name) => true])->all();

        $categories = Category::all()->mapWithKeys(fn (Category $c) => [self::key($c->name) => $c->name])->all();

        $branches = Branch::all()->filter(fn (Branch $b) => $user->canAccessBranch($b->id));
        $branchByName = [];

        foreach ($branches as $branch) {
            $branchByName[self::key($branch->name)] = $branch;

            if (filled($branch->code)) {
                $branchByName[self::key($branch->code)] = $branch;
            }
        }

        $default = $this->defaultBranch($user, $branches, $defaultBranchId);

        // Barcodes are unique per business, deleted variants included.
        $fileBarcodes = array_values(array_filter(array_map(fn ($r) => $r['values']['barcode'], $rows)));
        $takenBarcodes = [];

        foreach (array_chunk($fileBarcodes, 500) as $chunk) {
            foreach (ProductVariant::withTrashed()->whereIn('barcode', $chunk)->pluck('barcode') as $barcode) {
                $takenBarcodes[self::key($barcode)] = true;
            }
        }

        $products = [];
        $seenBarcodes = [];
        $newCategories = [];
        $existingCategories = [];

        foreach ($rows as ['line' => $line, 'values' => $v]) {
            $rowErrors = count($errors);

            $name = $v['name'];

            if ($name === null) {
                $fail($line, 'name', 'اسم المنتج مطلوب.');
            } elseif ($name === ImportTemplate::EXAMPLE_NAME) {
                $fail($line, 'name', 'ده سطر المثال بتاع القالب. امسحه قبل الرفع.');
            } elseif (mb_strlen($name) > 255) {
                $fail($line, 'name', 'اسم المنتج أطول من 255 حرف.');
            }

            $price = $this->decimal($v['price'], 'السعر', true, $line, 'price', $fail);
            $cost = $this->decimal($v['cost'], 'التكلفة', false, $line, 'cost', $fail);

            $stock = null;

            if ($v['opening_stock'] !== null) {
                $digits = self::digits($v['opening_stock']);

                if (! preg_match('/^\d+$/', $digits) || (int) $digits > 1000000) {
                    $fail($line, 'opening_stock', "الرصيد الافتتاحي لازم يبقى عدد صحيح من 0 لـ 1,000,000، مش «{$v['opening_stock']}».");
                } else {
                    $stock = (int) $digits;
                }
            }

            foreach (['size' => ['المقاس', 32], 'color' => ['اللون', 32], 'barcode' => ['الباركود', 64], 'category' => ['التصنيف', 255]] as $field => [$label, $max]) {
                if ($v[$field] !== null && mb_strlen($v[$field]) > $max) {
                    $fail($line, $field, "{$label} أطول من {$max} حرف.");
                }
            }

            // The branch matters only when stock is going in; a name that does
            // not match is still an error, so a typo is not silently ignored.
            $branchId = null;

            if ($v['branch'] !== null) {
                $branch = $branchByName[self::key($v['branch'])] ?? null;

                if ($branch === null) {
                    $fail($line, 'branch', "الفرع «{$v['branch']}» مش موجود أو مش مسموحلك بيه.");
                } else {
                    $branchId = $branch->id;
                }
            } elseif ($stock > 0) {
                $branchId = $default?->id;

                if ($branchId === null) {
                    $fail($line, 'branch', 'الرصيد الافتتاحي محتاج فرع: اكتبه في الخانة أو اختار فرع وقت الرفع.');
                }
            }

            $barcode = $v['barcode'];

            if ($barcode !== null) {
                $bk = self::key($barcode);

                if (isset($takenBarcodes[$bk])) {
                    $fail($line, 'barcode', "الباركود «{$barcode}» مستخدم في قطعة تانية.");
                } elseif (isset($seenBarcodes[$bk])) {
                    $fail($line, 'barcode', "الباركود «{$barcode}» مكرر في سطر {$seenBarcodes[$bk]}.");
                } else {
                    $seenBarcodes[$bk] = $line;
                }
            }

            if ($name === null) {
                continue;
            }

            $pk = self::key($name);

            if (isset($existingProducts[$pk])) {
                $fail($line, 'name', "المنتج «{$name}» موجود قبل كده (يمكن مؤرشف). منتج بنفس الاسم بيترفض.");
            }

            $group = &$products[$pk];
            $group ??= ['name' => $name, 'category' => null, 'categoryLine' => $line, 'variants' => [], 'seen' => []];

            if ($v['category'] !== null) {
                if ($group['category'] === null) {
                    $group['category'] = $v['category'];
                    $group['categoryLine'] = $line;
                } elseif (self::key($group['category']) !== self::key($v['category'])) {
                    $fail($line, 'category', "المنتج «{$name}» له تصنيفين مختلفين: «{$group['category']}» في سطر {$group['categoryLine']} و«{$v['category']}» هنا.");
                }
            }

            $vk = self::key($v['size'] ?? '').'|'.self::key($v['color'] ?? '');

            if (isset($group['seen'][$vk])) {
                $fail($line, 'size', "المنتج «{$name}» بنفس المقاس واللون موجود في سطر {$group['seen'][$vk]}.");
            } else {
                $group['seen'][$vk] = $line;
            }

            // Only rows that passed are queued; a failed file is not written anyway.
            if (count($errors) === $rowErrors) {
                $group['variants'][] = [
                    'line' => $line,
                    'size' => $v['size'],
                    'color' => $v['color'],
                    'price' => $price,
                    'cost' => $cost,
                    'barcode' => $barcode,
                    'stock' => $stock ?? 0,
                    'branch_id' => $branchId,
                ];
            }

            unset($group);
        }

        // Categories: which exist, which will be made, which look like a typo.
        foreach ($products as $group) {
            $category = $group['category'];

            if ($category === null) {
                continue;
            }

            $ck = self::key($category);

            if (isset($categories[$ck])) {
                $existingCategories[$ck] = $categories[$ck];
            } else {
                $newCategories[$ck] ??= $category;
            }
        }

        return [
            'errors' => $errors,
            'products' => $products,
            'newCategories' => $newCategories,
            'existingCategories' => $existingCategories,
            'similar' => $this->similar($newCategories, $categories),
        ];
    }

    /**
     * The branch used when a row says none. A branch manager's own; otherwise
     * what the owner picked; otherwise the only branch there is.
     */
    protected function defaultBranch(User $user, $branches, ?string $chosen): ?Branch
    {
        if ($chosen !== null) {
            return $branches->firstWhere('id', $chosen);
        }

        if (! $user->role->seesAllBranches()) {
            return $branches->firstWhere('id', $user->branch_id);
        }

        return $branches->count() === 1 ? $branches->first() : null;
    }

    /**
     * New categories that differ from another one only by spelling (ى/ي, ة/ه,
     * أ/ا): "رجالى" next to "رجالي". They are still created, as asked, but the
     * summary names them so the typo is seen instead of silently becoming a
     * second category.
     *
     * @param  array<string, string>  $new
     * @param  array<string, string>  $existing
     * @return array<int, array{name: string, like: string}>
     */
    protected function similar(array $new, array $existing): array
    {
        $byForm = [];

        foreach ($existing as $name) {
            $byForm[self::form($name)] ??= $name;
        }

        $similar = [];

        foreach ($new as $name) {
            $form = self::form($name);

            if (isset($byForm[$form])) {
                $similar[] = ['name' => $name, 'like' => $byForm[$form]];
            }

            $byForm[$form] ??= $name;
        }

        return $similar;
    }

    /* ---------- writing: one transaction ---------- */

    /**
     * @param  array{products: array<string, array>, newCategories: array<string, string>, existingCategories: array<string, string>, similar: array}  $plan
     */
    protected function write(User $user, array $plan): array
    {
        return DB::transaction(function () use ($user, $plan) {
            $ids = Category::all()->mapWithKeys(fn (Category $c) => [self::key($c->name) => $c->id])->all();

            foreach ($plan['newCategories'] as $key => $name) {
                $ids[$key] = Category::create(['name' => $name])->id;
            }

            $count = array_sum(array_map(fn ($p) => count($p['variants']), $plan['products']));
            $codes = $count > 0 ? SkuGenerator::take($count) : [];
            $next = 0;

            $movements = 0;
            $pieces = 0;

            foreach ($plan['products'] as $group) {
                $variants = [];

                foreach ($group['variants'] as $variant) {
                    $variants[] = [
                        'sku' => $codes[$next++],
                        'size' => $variant['size'],
                        'color' => $variant['color'],
                        'barcode' => $variant['barcode'],
                        'price' => $variant['price'],
                        'cost' => $variant['cost'],
                    ];
                }

                $product = $this->createProduct->handle(
                    user: $user,
                    name: $group['name'],
                    variants: $variants,
                    categoryId: $group['category'] !== null ? $ids[self::key($group['category'])] : null,
                );

                $bySku = $product->variants->keyBy('sku');

                foreach ($group['variants'] as $i => $variant) {
                    if ($variant['stock'] <= 0) {
                        continue;
                    }

                    $this->stock->post(
                        variant: $bySku[$variants[$i]['sku']],
                        branchId: $variant['branch_id'],
                        quantity: $variant['stock'],
                        type: StockMovementType::Purchase,
                        user: $user,
                        note: self::OPENING_NOTE,
                    );

                    $movements++;
                    $pieces += $variant['stock'];
                }
            }

            return [
                'products' => count($plan['products']),
                'variants' => $count,
                'categories_created' => array_values($plan['newCategories']),
                'categories_existing' => array_values($plan['existingCategories']),
                'similar_categories' => $plan['similar'],
                'stock_movements' => $movements,
                'stock_pieces' => $pieces,
            ];
        });
    }

    /* ---------- small helpers ---------- */

    /** A decimal with at most two places, as the string the column takes. */
    protected function decimal(?string $raw, string $label, bool $required, int $line, string $field, \Closure $fail): ?string
    {
        if ($raw === null) {
            if ($required) {
                $fail($line, $field, "{$label} مطلوب.");
            }

            return null;
        }

        $text = self::digits($raw);

        if (str_starts_with($text, '-') && preg_match('/^-\d+(\.\d+)?$/', $text)) {
            $fail($line, $field, "{$label} لازم يبقى صفر أو أكتر، مش «{$raw}».");

            return null;
        }

        if (! preg_match('/^\d+(\.\d+)?$/', $text)) {
            $fail($line, $field, "{$label} مش رقم: «{$raw}».");

            return null;
        }

        if (preg_match('/\.\d{3,}$/', $text)) {
            $fail($line, $field, "{$label} فيه أكتر من خانتين بعد العلامة العشرية: «{$raw}».");

            return null;
        }

        if ((float) $text > self::MAX_PRICE) {
            $fail($line, $field, "{$label} كبير أوي: «{$raw}».");

            return null;
        }

        return $text;
    }

    /** Arabic-Indic digits and the Arabic decimal mark, as plain ASCII. */
    protected static function digits(string $text): string
    {
        return str_replace(
            ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩', '۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹', '٫', ' '],
            ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9', '0', '1', '2', '3', '4', '5', '6', '7', '8', '9', '.', ''],
            $text,
        );
    }

    /** How names are compared: case and spacing do not make a different product. */
    protected static function key(string $text): string
    {
        return mb_strtolower(trim($text));
    }

    /** Spelling-insensitive form, for spotting typos only. */
    protected static function form(string $text): string
    {
        return str_replace(['أ', 'إ', 'آ', 'ى', 'ة'], ['ا', 'ا', 'ا', 'ي', 'ه'], self::key($text));
    }
}
