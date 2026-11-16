<?php

namespace Database\Seeders;

use App\Actions\Catalogue\CreateProduct;
use App\Actions\Returns\CreateSaleReturn;
use App\Actions\Selling\CreateInvoice;
use App\Actions\Selling\VoidInvoice;
use App\Actions\Shifts\CloseShift;
use App\Actions\Shifts\OpenShift;
use App\Actions\Stock\ReceiveStock;
use App\Actions\Stock\ReceiveTransfer;
use App\Actions\Stock\TransferStock;
use App\Enums\PaymentMethod;
use App\Enums\RefundMethod;
use App\Enums\Role;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Category;
use App\Models\Device;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Shift;
use App\Models\User;
use App\Services\DrawerCash;
use App\Support\BusinessContext;
use App\Support\Money;
use Carbon\Carbon;
use Closure;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * The demo shop, with enough on it to judge a layout: ~56 products, ~35
 * invoices over nine days, returns, voids, closed shifts, and two transfers.
 *
 *     php artisan db:seed --class=DemoVolumeSeeder
 *
 * NOT in DatabaseSeeder, on purpose: it is asked for, so `prepare_env.sh` and
 * the test suite keep the small shop. It refuses to run outside `local`, and
 * running it twice does nothing the second time.
 *
 * NOTHING HERE WRITES A ROW BY HAND. Every product, sale, void, return, shift
 * and transfer goes through the Action the till and dashboard use, so stock
 * only ever moves through StockLedger and the balances obey the same rules as
 * real use. The past is replayed in order with Carbon::setTestNow(), so each
 * action stamps its own dates (issued_at, voided_at, opened_at …) and the
 * drawer arithmetic in DrawerCash sees real time windows.
 */
class DemoVolumeSeeder extends Seeder
{
    /** What marks a run as done: the first product of the first category. */
    private const MARKER = 'قميص أوكسفورد كلاسيك';

    /**
     * Four names are long on purpose: a long name is what breaks a card.
     *
     * @var array<string, array{base: int, sizes: list<string>, colors: list<string>, products: list<string>}>
     */
    private const RANGE = [
        'رجالي' => [
            'base' => 350, 'sizes' => ['M', 'L', 'XL', 'XXL'], 'colors' => ['أبيض', 'أزرق', 'كحلي'],
            'products' => [
                self::MARKER, 'بنطلون شينو قطن', 'تيشيرت بولو بيكيه', 'سويت شيرت بكابوشون',
                'جاكيت بومبر خفيف', 'شورت كتان صيفي', 'بدلة رسمية سموكنج',
                'قميص كاجوال مقلم بأكمام طويلة مع جيب صدر وتطريز على الياقة',
            ],
        ],
        'حريمي' => [
            'base' => 450, 'sizes' => ['S', 'M', 'L'], 'colors' => ['وردي', 'أسود', 'بيج'],
            'products' => [
                'فستان سهرة ساتان', 'بلوزة شيفون', 'تنورة بليسيه', 'عباية كريب مطرزة',
                'طقم كارديجان وبنطلون', 'جيبة جينز', 'بلوفر صوف ناعم',
                'فستان ميدي كلوش مورد بحزام قماش وأكمام منفوخة للمناسبات الصيفية',
            ],
        ],
        'أطفال' => [
            'base' => 180, 'sizes' => ['4', '6', '8', '10'], 'colors' => ['أحمر', 'أخضر', 'سماوي'],
            'products' => [
                'تيشيرت ولادي طباعة', 'فستان بناتي تل', 'بيجامة قطن أطفال', 'شورت ولادي',
                'جاكيت أطفال بطانة فرو', 'سالوبيت جينز', 'طقم مدرسة', 'مريلة مدرسية',
            ],
        ],
        'أحذية' => [
            'base' => 600, 'sizes' => ['40', '41', '42', '43'], 'colors' => ['أسود', 'بني'],
            'products' => [
                'حذاء كاجوال جلد', 'سنيكرز أبيض', 'صندل حريمي', 'بوت شتوي',
                'شبشب بلاستيك', 'حذاء رسمي أسود', 'كوتشي جري',
                'كوتشي رياضي خفيف للجري والمشي لمسافات طويلة بنعل طبي مريح',
            ],
        ],
        'إكسسوارات' => [
            'base' => 120, 'sizes' => [], 'colors' => ['أسود', 'بني', 'رمادي'],
            'products' => [
                'حزام جلد', 'شال حرير', 'كاب قطن', 'نظارة شمس',
                'ساعة يد', 'وشاح صوف', 'قفازات شتوية', 'محفظة رجالي',
            ],
        ],
        'شنط' => [
            'base' => 400, 'sizes' => [], 'colors' => ['أسود', 'جملي', 'كحلي'],
            'products' => [
                'شنطة ظهر', 'شنطة يد حريمي', 'شنطة لاب توب', 'حقيبة سفر صغيرة',
                'شنطة كروس', 'حقيبة رياضية', 'شنطة مدرسة', 'شنطة كتف جلد طبيعي بتصميم عملي للشغل والسفر',
            ],
        ],
        'ملابس داخلية' => [
            'base' => 90, 'sizes' => ['S', 'M', 'L'], 'colors' => ['أبيض', 'رمادي'],
            'products' => [
                'فانلة قطن داخلية', 'بوكسر قطن عبوة 3', 'شراب رياضي عبوة 6', 'ملابس نوم حريمي',
                'بيجامة رجالي', 'رداء استحمام', 'جوارب بنطلون', 'طقم داخلي',
            ],
        ],
    ];

    /** Invoices per day, oldest day first (key = days ago). 32 over nine days; three more today. */
    private const PER_DAY = [9 => 4, 8 => 3, 7 => 4, 6 => 4, 5 => 3, 4 => 4, 3 => 4, 2 => 3, 1 => 3];

    /** Drawer count minus expected, in piastres, per day for the main till (zero, over and short). */
    private const A1_VARIANCE = [9 => 0, 8 => 2500, 7 => -4000, 6 => 0, 5 => -15000, 4 => 1000, 3 => 0, 2 => -2050, 1 => 0];

    /**
     * The second branch's till: opened on these days. Day 6 is never closed,
     * so day 5's opening closes it without a count.
     */
    private const B1_VARIANCE = [9 => 0, 7 => -6000, 5 => 1500, 3 => -550, 1 => 0];

    private const B1_OPEN_DAYS = [9, 7, 6, 5, 3, 1];

    private User $owner;

    private User $manager;

    private User $seller;

    private User $seller2;

    private Branch $main;

    private Branch $second;

    private Device $a1;

    private Device $b1;

    /** @var list<string> */
    private array $variantIds = [];

    /** @var array<int, string> invoice index => id, for the voids and returns */
    private array $made = [];

    /** @var array<int, list<array{0: Carbon, 1: Closure}>> */
    private array $events = [];

    private ?string $shortTransferId = null;

    public function run(): void
    {
        if (! app()->environment('local')) {
            throw new \RuntimeException('DemoVolumeSeeder only runs in the local environment (this is "'.app()->environment().'").');
        }

        if (Business::where('slug', 'demo-shop')->doesntExist()) {
            $this->call(DemoBusinessSeeder::class);
        }

        $context = app(BusinessContext::class);
        $context->setBusiness(Business::where('slug', 'demo-shop')->firstOrFail());

        try {
            if (Product::where('name', self::MARKER)->exists()) {
                $this->command?->info('Demo volume already seeded, left alone.');

                return;
            }

            DB::transaction(fn () => $this->replay());

            $this->command?->info('Demo volume seeded: '.Product::count().' products, '.Invoice::count().' invoices.');
        } finally {
            Carbon::setTestNow();
            $context->forgetBusiness();
        }
    }

    private function replay(): void
    {
        $this->owner = User::where('email', 'owner@demo-shop.local')->firstOrFail();
        $this->manager = User::where('email', 'manager@demo-shop.local')->firstOrFail();
        $this->seller = User::where('email', 'seller@demo-shop.local')->firstOrFail();
        $this->main = Branch::where('code', 'MAIN')->firstOrFail();
        $this->second = Branch::where('code', 'ASF')->firstOrFail();
        $this->a1 = Device::where('code', 'A1')->firstOrFail();
        $this->b1 = Device::where('code', 'B1')->firstOrFail();

        // The second branch's till needs someone to stand at it.
        $this->seller2 = User::firstOrCreate(['email' => 'seller2@demo-shop.local'], [
            'branch_id' => $this->second->id,
            'name' => 'بائع العصافرة',
            'phone' => '01000000004',
            'password' => 'password',
            'role' => Role::Seller,
            'is_active' => true,
        ]);

        $base = Carbon::now()->startOfDay();
        $stocked = $base->copy()->subDays(10)->setTime(8, 30);

        // A till that has not synced for days is refused by CreateInvoice; the
        // demo devices were stamped "now", which is the future from here.
        foreach ([$this->a1, $this->b1] as $device) {
            $device->forceFill(['last_sync_at' => $stocked])->save();
        }

        $this->stockTheShelves($stocked);
        $this->planDays($base);
        $this->planToday();

        foreach ($this->events as $day => $list) {
            usort($list, fn ($x, $y) => $x[0] <=> $y[0]);

            foreach ($list as [$at, $do]) {
                Carbon::setTestNow($at);
                $do();
            }
        }
    }

    /** Products with opening stock at the main branch, then a delivery to the second. */
    private function stockTheShelves(Carbon $at): void
    {
        Carbon::setTestNow($at);

        $create = app(CreateProduct::class);
        $n = 0;
        $second = [];

        foreach (self::RANGE as $categoryName => $spec) {
            $category = Category::firstOrCreate(['name' => $categoryName]);

            foreach ($spec['products'] as $j => $name) {
                $sizes = $spec['sizes'] === [] ? [null] : array_slice($spec['sizes'], 0, 1 + ($j % 3));
                $colors = array_slice($spec['colors'], 0, 1 + ($j % 2));
                $price = $spec['base'] + (($n * 37) % 9) * 15;
                $inputs = [];

                foreach ($sizes as $size) {
                    foreach ($colors as $color) {
                        $inputs[] = [
                            'size' => $size,
                            'color' => $color,
                            'price' => number_format($price, 2, '.', ''),
                            'cost' => number_format($price * 0.6, 2, '.', ''),
                            'opening_stock' => 10 + (($n++ * 7) % 15),
                        ];
                    }
                }

                $product = $create->handle($this->owner, $name, $inputs, $category->id, null, $this->main->id);

                foreach ($product->variants as $variant) {
                    $this->variantIds[] = $variant->id;
                    $second[] = ['variant_id' => $variant->id, 'quantity' => 5 + (count($this->variantIds) % 6)];
                }
            }
        }

        app(ReceiveStock::class)->handle($this->owner, $this->second->id, $second, 'توريد افتتاحي');
    }

    private function on(int $day, int $hour, int $minute, Carbon $base, Closure $do): void
    {
        $this->events[$day][] = [$base->copy()->subDays($day)->setTime($hour, $minute), $do];
    }

    private function planDays(Carbon $base): void
    {
        $open = app(OpenShift::class);
        $i = 0;

        foreach (self::PER_DAY as $day => $count) {
            $this->on($day, 9, 0, $base, fn () => $open->handle($this->seller, $this->a1, '500.00', now()));

            if (in_array($day, self::B1_OPEN_DAYS, true)) {
                $this->on($day, 9, 30, $base, fn () => $open->handle($this->seller2, $this->b1, '300.00', now()));
            }

            for ($k = 0; $k < $count; $k++, $i++) {
                $onB1 = $i % 3 === 2;
                $minutes = $k * 95 + ($onB1 ? 7 : 0);
                $index = $i;

                $this->on($day, 10 + intdiv($minutes, 60), $minutes % 60, $base, fn () => $this->sell($index, $onB1));
            }

            $this->closeDrawers($day, $base);
        }

        $this->planStories($base);
    }

    /** One ordinary sale; its shape is decided by its index so a rerun of the plan is the same. */
    private function sell(int $i, bool $onB1): void
    {
        $device = $onB1 ? $this->b1 : $this->a1;
        $lineDiscount = $i % 5 === 1;
        $invoiceDiscount = $i % 5 === 3;
        $total = count($this->variantIds);
        $items = [];

        for ($j = 0; $j <= $i % 3; $j++) {
            $items[] = [
                'variant_id' => $this->variantIds[($i * 13 + $j * 29) % $total],
                'quantity' => 1 + (($i + $j) % 3),
                'discount_amount' => $lineDiscount && $j === 0 ? ['15.00', '22.50', '40.00'][$i % 3] : 0,
            ];
        }

        // Only a manager or the owner may discount (sellers are switched off in the settings).
        $actor = ($lineDiscount || $invoiceDiscount)
            ? ($onB1 ? $this->owner : $this->manager)
            : ($onB1 ? $this->seller2 : $this->seller);

        $invoice = app(CreateInvoice::class)->handle(
            $actor,
            $device,
            $items,
            $invoiceDiscount ? ['30.00', '45.50', '12.75'][$i % 3] : '0.00',
            [PaymentMethod::Cash, PaymentMethod::Cash, PaymentMethod::Card, PaymentMethod::Wallet][$i % 4],
            $i % 4 === 0 ? 'عميل نقدي' : null,
        );

        $this->made[$i] = $invoice->id;
    }

    /** Voids, returns, transfers and the invoice that makes the refund figures interesting. */
    private function planStories(Carbon $base): void
    {
        // Two voids, a little after the sale: one at each branch.
        $this->on(7, 14, 10, $base, fn () => $this->void(9, $this->manager, 'اتسجلت بالغلط'));
        $this->on(3, 14, 30, $base, fn () => $this->void(23, $this->owner, 'العميل غيّر رأيه قبل الدفع'));

        // Returns: a line that was discounted, a damaged piece, and store credit.
        $this->on(8, 17, 0, $base, fn () => $this->giveBack(6, $this->manager, $this->a1, RefundMethod::Cash, 'المقاس مش مناسب', 'good'));
        $this->on(4, 16, 15, $base, fn () => $this->giveBack(20, $this->owner, $this->b1, RefundMethod::Cash, 'عيب في الخياطة', 'damaged'));
        $this->on(2, 18, 0, $base, fn () => $this->giveBack(27, $this->manager, $this->a1, RefundMethod::StoreCredit, 'غيّر رأيه', 'good'));

        // Three of one piece with a line discount AND an invoice discount, then one
        // of them back: the refund is a share of both, not a round number.
        $this->on(4, 12, 0, $base, function () {
            $discounted = $this->variantIds[40];

            $invoice = app(CreateInvoice::class)->handle(
                $this->manager,
                $this->a1,
                [
                    ['variant_id' => $discounted, 'quantity' => 3, 'discount_amount' => '60.00'],
                    ['variant_id' => $this->variantIds[41], 'quantity' => 1],
                ],
                '33.33',
                PaymentMethod::Cash,
                'مدام منى',
            );

            $this->made[100] = $invoice->id;
        });
        $this->on(4, 14, 0, $base, fn () => $this->giveBack(100, $this->manager, $this->a1, RefundMethod::Cash, 'المقاس ضيق', 'good'));

        // Sent on day 5, counted on day 4: two short, left open for the dashboard to show.
        $this->on(5, 15, 0, $base, function () {
            $result = app(TransferStock::class)->handle($this->owner, $this->main->id, $this->second->id, [
                ['variant_id' => $this->variantIds[3], 'quantity' => 6],
                ['variant_id' => $this->variantIds[8], 'quantity' => 4],
            ], 'توريد للفرع');

            $this->shortTransferId = $result['transfer']->id;
        });
        $this->on(4, 10, 30, $base, fn () => app(ReceiveTransfer::class)->handle($this->owner, $this->shortTransferId, [
            ['variant_id' => $this->variantIds[3], 'quantity' => 4],
            ['variant_id' => $this->variantIds[8], 'quantity' => 4],
        ]));
    }

    private function void(int $index, User $by, string $reason): void
    {
        app(VoidInvoice::class)->handle($by, Invoice::findOrFail($this->made[$index]), $reason);
    }

    /** One line, one piece, back. */
    private function giveBack(int $index, User $by, Device $device, RefundMethod $method, string $reason, string $condition): void
    {
        $invoice = Invoice::with('items')->findOrFail($this->made[$index]);
        $line = $index === 100 ? $invoice->items->firstWhere('variant_id', $this->variantIds[40]) : $invoice->items->first();

        app(CreateSaleReturn::class)->handle(
            $by,
            $device,
            $invoice,
            [['invoice_item_id' => $line->id, 'quantity' => 1, 'condition' => $condition]],
            $method,
            $reason,
        );
    }

    /** Count each drawer a little off its expected figure, the way a real count is. */
    private function closeDrawers(int $day, Carbon $base): void
    {
        $this->on($day, 21, 0, $base, fn () => $this->close($this->a1, $this->seller, self::A1_VARIANCE[$day], $day === 5 ? 'العدّ ناقص، فيه فكّة اتدفعت من الدرج' : null));

        if (array_key_exists($day, self::B1_VARIANCE)) {
            $this->on($day, 21, 15, $base, fn () => $this->close($this->b1, $this->seller2, self::B1_VARIANCE[$day], null));
        }
    }

    private function close(Device $device, User $by, int $varianceMinor, ?string $note): void
    {
        $shift = Shift::where('device_id', $device->id)->whereNull('closed_at')->firstOrFail();
        $expected = app(DrawerCash::class)->expectedMinor(
            $device->id,
            Money::toMinor($shift->opening_float),
            $shift->opened_at,
            now(),
        );

        app(CloseShift::class)->handle($by, $shift, Money::toDecimal(max(0, $expected + $varianceMinor)), $note, now());
    }

    /** Today, so the day's list is not empty and one shift is still open. */
    private function planToday(): void
    {
        $now = Carbon::now();

        $this->events[0] = [
            [$now->copy()->subMinutes(55), fn () => app(OpenShift::class)->handle($this->seller, $this->a1, '400.00', now())],
            [$now->copy()->subMinutes(45), fn () => $this->sell(200, false)],
            [$now->copy()->subMinutes(30), fn () => $this->sell(201, false)],
            [$now->copy()->subMinutes(20), fn () => $this->sell(202, true)],
            // Left in transit: in neither branch's balance until the second counts it.
            [$now->copy()->subMinutes(10), fn () => app(TransferStock::class)->handle($this->owner, $this->main->id, $this->second->id, [
                ['variant_id' => $this->variantIds[15], 'quantity' => 3],
                ['variant_id' => $this->variantIds[20], 'quantity' => 2],
            ], 'شحنة في الطريق')],
        ];
    }
}
