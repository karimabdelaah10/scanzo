<?php

namespace Tests\Feature;

use App\Enums\StockMovementType;
use App\Exceptions\DomainException;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\StockLedger;
use App\Support\BusinessContext;
use App\Support\Import\CatalogueFileReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class CatalogueImportTest extends TestCase
{
    use RefreshDatabase;

    protected const HEAD = 'اسم المنتج,التصنيف,المقاس,اللون,السعر,التكلفة,الباركود,الرصيد الافتتاحي,الفرع';

    /** @param  array<int, string>  $lines */
    protected function csv(array $lines, bool $bom = true): UploadedFile
    {
        $text = ($bom ? "\xEF\xBB\xBF" : '').implode("\r\n", [self::HEAD, ...$lines])."\r\n";

        return UploadedFile::fake()->createWithContent('products.csv', $text);
    }

    protected function upload(User $user, UploadedFile $file, array $extra = [])
    {
        return $this->actingAs($user)->post('/api/catalogue/import', ['file' => $file] + $extra, ['Accept' => 'application/json']);
    }

    /** @return array{0: User, 1: Branch} */
    protected function shop(): array
    {
        $this->tenant();
        $branch = Branch::factory()->create(['name' => 'الفرع الرئيسي']);
        $owner = User::factory()->owner()->create();

        return [$owner, $branch];
    }

    protected function nothingWritten(): void
    {
        $this->assertSame(0, Product::withTrashed()->count());
        $this->assertSame(0, ProductVariant::withTrashed()->count());
        $this->assertSame(0, Category::count());
        $this->assertSame(0, StockMovement::count());
    }

    public function test_a_good_file_makes_products_variants_codes_and_opening_stock(): void
    {
        [$owner, $branch] = $this->shop();

        $response = $this->upload($owner, $this->csv([
            'قميص كتان,رجالي,M,أزرق,450,260,6221000000011,10,',
            'قميص كتان,رجالي,L,أزرق,450,260,,5,',
            'فستان صيفي,حريمي,,أحمر,799.50,,,,',
        ]), ['branch_id' => $branch->id])->assertCreated();

        $response->assertJsonPath('data.products', 2)
            ->assertJsonPath('data.variants', 3)
            ->assertJsonPath('data.stock_movements', 2)
            ->assertJsonPath('data.stock_pieces', 15);

        $this->assertSame(3, ProductVariant::count());
        $this->assertSame(
            ['SKU-10001', 'SKU-10002', 'SKU-10003'],
            ProductVariant::orderBy('sku')->pluck('sku')->all(),
        );

        $m = ProductVariant::where('size', 'M')->first();
        $this->assertSame('6221000000011', $m->barcode);
        $this->assertSame('450.00', (string) $m->price);
        $this->assertSame('260.00', (string) $m->cost);
        $this->assertSame('799.50', (string) ProductVariant::where('color', 'أحمر')->first()->price);

        $ledger = app(StockLedger::class);
        $this->assertSame(10, $ledger->balance($m->id, $branch->id));
        $this->assertSame(5, $ledger->balance(ProductVariant::where('size', 'L')->first()->id, $branch->id));
        $this->assertSame(0, $ledger->balance(ProductVariant::where('color', 'أحمر')->first()->id, $branch->id));
    }

    public function test_opening_stock_is_a_purchase_movement_in_the_ledger(): void
    {
        [$owner, $branch] = $this->shop();

        $this->upload($owner, $this->csv(['قميص,رجالي,M,أزرق,450,,,10,']), ['branch_id' => $branch->id])->assertCreated();

        $movement = StockMovement::sole();

        $this->assertSame(StockMovementType::Purchase, $movement->type);
        $this->assertSame(10, $movement->quantity);
        $this->assertSame($branch->id, $movement->branch_id);
        $this->assertSame($owner->id, $movement->user_id);
        $this->assertStringContainsString('رصيد افتتاحي', $movement->note);
    }

    public function test_a_row_may_name_its_own_branch(): void
    {
        [$owner, $branch] = $this->shop();
        $second = Branch::factory()->create(['name' => 'فرع المعادي']);

        $this->upload($owner, $this->csv([
            'قميص,رجالي,M,أزرق,450,,,10,فرع المعادي',
            'قميص,رجالي,L,أزرق,450,,,4,',
        ]), ['branch_id' => $branch->id])->assertCreated();

        $ledger = app(StockLedger::class);
        $m = ProductVariant::where('size', 'M')->first();
        $l = ProductVariant::where('size', 'L')->first();

        $this->assertSame(10, $ledger->balance($m->id, $second->id));
        $this->assertSame(0, $ledger->balance($m->id, $branch->id));
        $this->assertSame(4, $ledger->balance($l->id, $branch->id));
    }

    public function test_one_bad_row_writes_nothing_and_the_error_names_the_row(): void
    {
        [$owner, $branch] = $this->shop();

        $response = $this->upload($owner, $this->csv([
            'قميص,رجالي,M,أزرق,450,,,10,',
            'بنطلون,رجالي,32,أسود,abc,,,3,',
            'جاكيت,رجالي,L,بني,900,,,2,',
        ]), ['branch_id' => $branch->id])->assertUnprocessable();

        $response->assertJsonPath('code', 'import_rejected')
            ->assertJsonPath('context.error_count', 1)
            ->assertJsonPath('context.errors.0.row', 3)
            ->assertJsonPath('context.errors.0.field', 'price');

        $this->assertStringContainsString('السعر مش رقم', $response->json('context.errors.0.message'));
        $this->nothingWritten();
    }

    public function test_every_error_is_reported_at_once(): void
    {
        [$owner, $branch] = $this->shop();

        $response = $this->upload($owner, $this->csv([
            ',رجالي,M,أزرق,450,,,,',          // row 2: no name
            'بنطلون,رجالي,32,أسود,-5,,,,',    // row 3: negative price
            'جاكيت,رجالي,L,بني,,,,,',         // row 4: no price
            'تيشيرت,رجالي,L,بني,100,,,1.5,',  // row 5: stock not whole
            'تيشيرت2,رجالي,L,بني,100,,,,نص',  // row 6: unknown branch
            'سليم,رجالي,L,بني,100,,,,',       // row 7: fine
        ]), ['branch_id' => $branch->id])->assertUnprocessable();

        $rows = collect($response->json('context.errors'))->pluck('row')->sort()->values()->all();

        $this->assertSame([2, 3, 4, 5, 6], $rows);
        $this->nothingWritten();
    }

    public function test_an_existing_product_name_is_refused_and_nothing_else_is_saved(): void
    {
        [$owner, $branch] = $this->shop();

        $this->upload($owner, $this->csv(['قميص كتان,رجالي,M,أزرق,450,,,,']))->assertCreated();

        $response = $this->upload($owner, $this->csv([
            'قميص كتان,رجالي,L,أزرق,450,,,,',
            'بنطلون جديد,رجالي,32,أسود,300,,,,',
        ]))->assertUnprocessable();

        $response->assertJsonPath('context.error_count', 1)
            ->assertJsonPath('context.errors.0.row', 2)
            ->assertJsonPath('context.errors.0.field', 'name');

        // Only the first upload's product exists.
        $this->assertSame(['قميص كتان'], Product::pluck('name')->all());
        $this->assertSame(1, ProductVariant::count());
    }

    public function test_an_archived_product_name_is_refused_too(): void
    {
        [$owner] = $this->shop();

        $this->upload($owner, $this->csv(['قميص,رجالي,M,أزرق,450,,,,']))->assertCreated();
        Product::first()->delete();

        $this->upload($owner, $this->csv(['قميص,رجالي,M,أزرق,450,,,,']))->assertUnprocessable();
    }

    public function test_uploading_the_same_file_twice_is_refused_not_doubled(): void
    {
        [$owner] = $this->shop();
        $lines = ['قميص,رجالي,M,أزرق,450,,,,', 'قميص,رجالي,L,أزرق,450,,,,'];

        $this->upload($owner, $this->csv($lines))->assertCreated();
        $this->upload($owner, $this->csv($lines))->assertUnprocessable();

        $this->assertSame(1, Product::count());
        $this->assertSame(2, ProductVariant::count());
    }

    public function test_rows_with_the_same_name_become_one_product_with_several_variants(): void
    {
        [$owner] = $this->shop();

        $this->upload($owner, $this->csv([
            'قميص كتان,رجالي,M,أزرق,450,,,,',
            'تيشيرت,رجالي,M,أبيض,200,,,,',
            'قميص كتان,رجالي,L,أزرق,450,,,,',
            'قميص كتان,,L,أبيض,470,,,,',   // category left blank: still the same product
        ]))->assertCreated()
            ->assertJsonPath('data.products', 2)
            ->assertJsonPath('data.variants', 4);

        $shirt = Product::where('name', 'قميص كتان')->sole();

        $this->assertCount(3, $shirt->variants);
        $this->assertNotNull($shirt->category_id);
    }

    public function test_the_same_size_and_colour_twice_in_a_product_is_an_error(): void
    {
        [$owner] = $this->shop();

        $response = $this->upload($owner, $this->csv([
            'قميص,رجالي,M,أزرق,450,,,,',
            'قميص,رجالي,M,أزرق,450,,,,',
        ]))->assertUnprocessable();

        $response->assertJsonPath('context.errors.0.row', 3);
        $this->nothingWritten();
    }

    public function test_one_product_with_two_categories_is_an_error(): void
    {
        [$owner] = $this->shop();

        $this->upload($owner, $this->csv([
            'قميص,رجالي,M,أزرق,450,,,,',
            'قميص,حريمي,L,أزرق,450,,,,',
        ]))->assertUnprocessable()->assertJsonPath('context.errors.0.field', 'category');

        $this->nothingWritten();
    }

    public function test_a_new_category_is_created_and_named_in_the_summary(): void
    {
        [$owner] = $this->shop();
        Category::create(['name' => 'حريمي']);

        $this->upload($owner, $this->csv([
            'قميص,رجالي,M,أزرق,450,,,,',
            'فستان,حريمي,M,أحمر,800,,,,',
        ]))->assertCreated()
            ->assertJsonPath('data.categories_created', ['رجالي'])
            ->assertJsonPath('data.categories_existing', ['حريمي']);

        $this->assertSame(2, Category::count());
        $this->assertSame(Category::where('name', 'رجالي')->value('id'), Product::where('name', 'قميص')->value('category_id'));
    }

    public function test_a_misspelt_category_is_created_but_flagged_as_like_an_existing_one(): void
    {
        [$owner] = $this->shop();
        Category::create(['name' => 'رجالي']);

        $this->upload($owner, $this->csv(['قميص,رجالى,M,أزرق,450,,,,']))
            ->assertCreated()
            ->assertJsonPath('data.categories_created', ['رجالى'])
            ->assertJsonPath('data.similar_categories.0.name', 'رجالى')
            ->assertJsonPath('data.similar_categories.0.like', 'رجالي');
    }

    public function test_a_barcode_already_in_the_shop_or_repeated_in_the_file_is_an_error(): void
    {
        [$owner] = $this->shop();

        $this->upload($owner, $this->csv(['قميص,رجالي,M,أزرق,450,,111,,']))->assertCreated();

        $response = $this->upload($owner, $this->csv([
            'بنطلون,رجالي,32,أسود,300,,111,,',
            'جاكيت,رجالي,L,بني,900,,222,,',
            'حذاء,رجالي,42,أسود,900,,222,,',
        ]))->assertUnprocessable();

        $this->assertSame([2, 4], collect($response->json('context.errors'))->pluck('row')->all());
    }

    public function test_opening_stock_without_any_branch_to_put_it_in_is_an_error(): void
    {
        [$owner] = $this->shop();
        Branch::factory()->create(['name' => 'فرع تاني']);

        // Two branches, none chosen, none named.
        $this->upload($owner, $this->csv(['قميص,رجالي,M,أزرق,450,,,10,']))
            ->assertUnprocessable()
            ->assertJsonPath('context.errors.0.field', 'branch');

        $this->nothingWritten();
    }

    public function test_the_only_branch_is_used_when_none_is_given(): void
    {
        [$owner, $branch] = $this->shop();

        $this->upload($owner, $this->csv(['قميص,رجالي,M,أزرق,450,,,10,']))->assertCreated();

        $this->assertSame($branch->id, StockMovement::sole()->branch_id);
    }

    public function test_a_branch_manager_imports_into_their_own_branch_only(): void
    {
        [, $branch] = $this->shop();
        $other = Branch::factory()->create(['name' => 'فرع المعادي']);
        $manager = $this->manager($branch);

        $this->upload($manager, $this->csv(['قميص,رجالي,M,أزرق,450,,,10,']))->assertCreated();
        $this->assertSame($branch->id, StockMovement::sole()->branch_id);

        $this->upload($manager, $this->csv(['بنطلون,رجالي,32,أسود,300,,,10,فرع المعادي']))
            ->assertUnprocessable()
            ->assertJsonPath('context.errors.0.field', 'branch');

        $this->upload($manager, $this->csv(['جاكيت,رجالي,L,بني,900,,,1,']), ['branch_id' => $other->id])
            ->assertUnprocessable();
    }

    public function test_a_seller_cannot_import(): void
    {
        $this->tenant();
        [, , $seller] = $this->shopFloor();

        $this->upload($seller, $this->csv(['قميص,رجالي,M,أزرق,450,,,,']))->assertForbidden();
        $this->assertSame(0, Product::count());
    }

    public function test_another_shops_data_is_invisible_to_the_import(): void
    {
        // Shop B has everything shop A's file is about to use.
        $this->tenant();
        $foreign = Branch::factory()->create(['name' => 'فرع المعادي']);
        $this->actingAs(User::factory()->owner()->create());
        Category::create(['name' => 'رجالي']);
        $theirs = Product::create(['name' => 'جاكيت', 'is_active' => true]);
        ProductVariant::create(['product_id' => $theirs->id, 'sku' => 'SKU-10001', 'barcode' => 'B-1', 'price' => 10, 'is_active' => true]);

        // Shop A.
        [$owner, $branch] = $this->shop();

        // Its branch and nothing else: B's branch name does not resolve.
        $this->upload($owner, $this->csv(['جاكيت,رجالي,L,بني,900,,B-1,2,فرع المعادي']))
            ->assertUnprocessable()
            ->assertJsonPath('context.errors.0.field', 'branch');

        // B's product name, barcode and category do not block A, and A's
        // import neither reuses B's category nor touches B's product.
        $this->upload($owner, $this->csv(['جاكيت,رجالي,L,بني,900,,B-1,2,']))
            ->assertCreated()
            ->assertJsonPath('data.categories_created', ['رجالي']);

        $mine = Product::sole();
        $this->assertNotSame($theirs->id, $mine->id);
        $this->assertSame($owner->business_id, $mine->business_id);

        app(BusinessContext::class)->setBusinessId($foreign->business_id);
        $this->assertSame(1, Product::count());
        $this->assertSame(1, Category::count());
        $this->assertNotSame($mine->category_id, Category::sole()->id);
    }

    public function test_excel_on_windows_writes_arabic_csv_in_windows_1256_and_it_still_reads(): void
    {
        [$owner, $branch] = $this->shop();

        $text = implode("\r\n", [self::HEAD, 'قميص كتان,رجالي,M,أزرق,450,,,10,'])."\r\n";
        $bytes = iconv('UTF-8', 'Windows-1256', $text);

        $this->assertFalse(mb_check_encoding($bytes, 'UTF-8'), 'the fixture must really be non-UTF-8');

        $this->upload($owner, UploadedFile::fake()->createWithContent('products.csv', $bytes))->assertCreated();

        $this->assertSame('قميص كتان', Product::sole()->name);
        $this->assertSame('رجالي', Category::sole()->name);
        $this->assertSame('أزرق', ProductVariant::sole()->color);
    }

    public function test_semicolons_arabic_digits_and_odd_heading_spelling_are_accepted(): void
    {
        [$owner, $branch] = $this->shop();

        $text = "الاسم;الفئة;مقاس;اللون;سعر البيع;التكلفه;الباركود;الرصيد;الفرع\r\n"
            ."قميص;رجالي;M;أزرق;٤٥٠٫٥;;;١٢;\r\n";

        // "مقاس" and "التكلفه" are not the headings we know: they are ignored
        // and said so, not guessed at.
        $this->upload($owner, UploadedFile::fake()->createWithContent('p.csv', $text))
            ->assertCreated()
            ->assertJsonPath('data.ignored_columns', ['مقاس', 'التكلفه']);

        $variant = ProductVariant::sole();

        $this->assertSame('450.50', (string) $variant->price);
        $this->assertNull($variant->size);
        $this->assertSame(12, app(StockLedger::class)->balance($variant->id, $branch->id));
    }

    public function test_the_templates_example_rows_are_refused(): void
    {
        [$owner] = $this->shop();

        $template = $this->actingAs($owner)->get('/api/catalogue/import/template?format=csv')->assertOk();
        $file = UploadedFile::fake()->createWithContent('t.csv', $template->streamedContent());

        $response = $this->upload($owner, $file)->assertUnprocessable();

        $this->assertSame([2, 3], collect($response->json('context.errors'))->pluck('row')->all());
        $this->nothingWritten();
    }

    public function test_a_missing_required_column_is_a_file_error(): void
    {
        [$owner] = $this->shop();

        $file = UploadedFile::fake()->createWithContent('p.csv', "اسم المنتج,المقاس\r\nقميص,M\r\n");

        $this->upload($owner, $file)
            ->assertUnprocessable()
            ->assertJsonPath('code', 'import_missing_columns')
            ->assertJsonPath('context.missing', ['السعر']);
    }

    public function test_more_than_the_row_limit_is_refused_before_anything_is_saved(): void
    {
        [$owner] = $this->shop();

        $lines = [];
        for ($i = 1; $i <= CatalogueFileReader::MAX_ROWS + 1; $i++) {
            $lines[] = "منتج {$i},رجالي,M,أزرق,10,,,,";
        }

        $this->upload($owner, $this->csv($lines))
            ->assertUnprocessable()
            ->assertJsonPath('code', 'import_too_large');

        $this->nothingWritten();
    }

    public function test_a_file_at_the_row_limit_imports_in_reasonable_time(): void
    {
        [$owner, $branch] = $this->shop();

        $lines = [];
        for ($i = 1; $i <= CatalogueFileReader::MAX_ROWS; $i++) {
            $product = intdiv($i - 1, 4) + 1;
            $lines[] = "منتج {$product},تصنيف ".($product % 5).',S'.($i % 4).',أزرق,100,60,,3,';
        }

        $started = microtime(true);
        $this->upload($owner, $this->csv($lines), ['branch_id' => $branch->id])->assertCreated();
        $seconds = microtime(true) - $started;

        fwrite(STDERR, sprintf("\n[import] %d rows in %.2fs\n", CatalogueFileReader::MAX_ROWS, $seconds));

        $this->assertSame(CatalogueFileReader::MAX_ROWS, ProductVariant::count());
        $this->assertSame(CatalogueFileReader::MAX_ROWS, StockMovement::count());
        $this->assertLessThan(15, $seconds, 'a synchronous import must stay well inside the 30 s PHP limit');
    }

    public function test_an_xlsx_without_the_library_says_the_command_instead_of_failing(): void
    {
        if (CatalogueFileReader::xlsxAvailable()) {
            $this->markTestSkipped('The spreadsheet package is installed here.');
        }

        [$owner] = $this->shop();

        // A zip header is all it takes to be treated as .xlsx.
        $file = UploadedFile::fake()->createWithContent('p.xlsx', "PK\x03\x04".str_repeat("\0", 64));

        $this->upload($owner, $file)
            ->assertUnprocessable()
            ->assertJsonPath('code', 'import_library_missing')
            ->assertJsonPath('context.command', DomainException::IMPORT_LIBRARY_COMMAND);

        $this->nothingWritten();
    }

    public function test_an_xlsx_round_trip_when_the_library_is_installed(): void
    {
        if (! CatalogueFileReader::xlsxAvailable()) {
            $this->markTestSkipped('Needs phpoffice/phpspreadsheet.');
        }

        [$owner, $branch] = $this->shop();

        $book = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $book->getActiveSheet()->fromArray([
            explode(',', self::HEAD),
            ['قميص كتان', 'رجالي', 'M', 'أزرق', 450, 260, 6221000000011, 10, null],
            ['قميص كتان', 'رجالي', 'L', 'أزرق', 450, 260, null, 5, null],
        ]);

        $path = tempnam(sys_get_temp_dir(), 'imp').'.xlsx';
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save($path);

        $this->upload($owner, new UploadedFile($path, 'p.xlsx', null, null, true), ['branch_id' => $branch->id])
            ->assertCreated()
            ->assertJsonPath('data.variants', 2);

        // A barcode stored as a number must not become 6.2212E+12.
        $this->assertSame('6221000000011', ProductVariant::where('size', 'M')->value('barcode'));

        $xlsx = $this->actingAs($owner)->get('/api/catalogue/import/template')->assertOk();
        $this->assertStringContainsString('.xlsx', $xlsx->headers->get('content-disposition'));
    }

    public function test_the_template_csv_is_utf8_with_a_bom_so_excel_shows_arabic(): void
    {
        [$owner] = $this->shop();

        $body = $this->actingAs($owner)->get('/api/catalogue/import/template?format=csv')->assertOk()->streamedContent();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $body);
        $this->assertStringContainsString('اسم المنتج', $body);
        $this->assertStringContainsString('الرصيد الافتتاحي', $body);
    }
}
