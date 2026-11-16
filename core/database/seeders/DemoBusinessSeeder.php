<?php

namespace Database\Seeders;

use App\Enums\BusinessStatus;
use App\Enums\DeviceType;
use App\Enums\InvoiceStatus;
use App\Enums\ItemCondition;
use App\Enums\PaymentMethod;
use App\Enums\RefundMethod;
use App\Enums\Role;
use App\Enums\StockMovementType;
use App\Enums\SubscriptionStatus;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Category;
use App\Models\Device;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SaleReturn;
use App\Models\SaleReturnItem;
use App\Models\StockMovement;
use App\Models\Subscription;
use App\Models\User;
use App\Support\BusinessContext;
use App\Support\Money;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * One worked-through business so the app has something to open: two branches,
 * three users, two tills, a catalogue with stock, one invoice and one return
 * against it.
 *
 * Everything after the business is created through BusinessContext rather than
 * by passing business_id around, which is how the application itself works.
 */
class DemoBusinessSeeder extends Seeder
{
    protected int $skuCounter = 10000;

    public function run(): void
    {
        $business = Business::where('slug', 'demo-shop')->first();

        if ($business !== null) {
            $this->command->info('Demo business already seeded, left alone.');

            return;
        }

        DB::transaction(function () {
            $business = Business::create([
                'name' => 'ملابس النخبة',
                'slug' => 'demo-shop',
                'status' => BusinessStatus::Active,
                'phone' => '01000000000',
                'settings' => config('scanzo.business_defaults'),
            ]);

            // From here on, every create picks up business_id by itself.
            app(BusinessContext::class)->setBusiness($business);

            Subscription::create([
                'plan' => 'standard',
                'status' => SubscriptionStatus::Active,
                'starts_at' => now()->startOfMonth(),
                'ends_at' => now()->addYear(),
                'offline_grace_days' => 7,
                'max_branches' => 3,
                'max_users' => 10,
                'max_devices' => 5,
            ]);

            [$main, $second] = $this->branches();
            [$owner, $manager, $seller] = $this->users($main, $second);
            [$till, $counter] = $this->devices($main, $second);
            $variants = $this->catalogue();
            $this->openingStock($variants, $main, $second, $owner);

            $invoice = $this->sampleInvoice($variants, $main, $till, $seller);
            $this->sampleReturn($invoice, $main, $till, $manager);

            app(BusinessContext::class)->forgetBusiness();

            $this->command->info('Demo business seeded: ملابس النخبة (demo-shop)');
            $this->command->info('  owner@demo-shop.local / manager@demo-shop.local / seller@demo-shop.local — password: password');
        });
    }

    /** @return array{0: Branch, 1: Branch} */
    protected function branches(): array
    {
        return [
            Branch::create([
                'name' => 'الفرع الرئيسي - سموحة',
                'code' => 'MAIN',
                'address' => 'سموحة، الإسكندرية',
                'phone' => '03000000',
                'is_active' => true,
            ]),
            Branch::create([
                'name' => 'فرع العصافرة',
                'code' => 'ASF',
                'address' => 'العصافرة، الإسكندرية',
                'is_active' => true,
            ]),
        ];
    }

    /** @return array{0: User, 1: User, 2: User} */
    protected function users(Branch $main, Branch $second): array
    {
        return [
            // No branch_id: an owner works across all of them.
            User::create([
                'name' => 'صاحب المحل',
                'email' => 'owner@demo-shop.local',
                'phone' => '01000000001',
                'password' => 'password',
                'role' => Role::Owner,
                'is_active' => true,
            ]),
            User::create([
                'branch_id' => $main->id,
                'name' => 'مدير الفرع الرئيسي',
                'email' => 'manager@demo-shop.local',
                'phone' => '01000000002',
                'password' => 'password',
                'role' => Role::BranchManager,
                'is_active' => true,
            ]),
            User::create([
                'branch_id' => $main->id,
                'name' => 'بائع',
                'email' => 'seller@demo-shop.local',
                'phone' => '01000000003',
                'password' => 'password',
                'role' => Role::Seller,
                'is_active' => true,
            ]),
        ];
    }

    /** @return array{0: Device, 1: Device} */
    protected function devices(Branch $main, Branch $second): array
    {
        return [
            Device::create([
                'branch_id' => $main->id,
                'name' => 'موبايل البائع',
                'code' => 'A1',
                'type' => DeviceType::Mobile,
                'last_sync_at' => now(),
                'is_active' => true,
            ]),
            Device::create([
                'branch_id' => $second->id,
                'name' => 'كاشير العصافرة',
                'code' => 'B1',
                'type' => DeviceType::Desktop,
                'last_sync_at' => now(),
                'is_active' => true,
            ]),
        ];
    }

    /** @return \Illuminate\Support\Collection<int, ProductVariant> */
    protected function catalogue(): \Illuminate\Support\Collection
    {
        $men = Category::create(['name' => 'رجالي']);
        $women = Category::create(['name' => 'حريمي']);
        $shoes = Category::create(['name' => 'أحذية']);

        $plan = [
            [$men, 'قميص كتان رجالي', 450, 260, ['S', 'M', 'L', 'XL'], ['أزرق', 'أبيض']],
            [$men, 'بنطلون جينز سليم', 650, 390, ['30', '32', '34'], ['أسود', 'أزرق غامق']],
            [$men, 'تيشيرت قطن بيزك', 220, 110, ['M', 'L', 'XL'], ['أبيض', 'كحلي']],
            [$men, 'جاكيت جلد', 1850, 1200, ['L', 'XL'], ['بني']],
            [$women, 'فستان صيفي', 780, 450, ['S', 'M', 'L'], ['وردي', 'أصفر']],
            [$shoes, 'حذاء كاجوال', 990, 600, ['41', '42', '43'], ['رمادي']],
        ];

        $variants = collect();

        foreach ($plan as [$category, $name, $price, $cost, $sizes, $colors]) {
            $product = Product::create([
                'category_id' => $category->id,
                'name' => $name,
                'is_active' => true,
            ]);

            foreach ($sizes as $size) {
                foreach ($colors as $color) {
                    $sku = 'SKU-'.(++$this->skuCounter);

                    $variants->push(ProductVariant::create([
                        'product_id' => $product->id,
                        'sku' => $sku,
                        'barcode' => (string) (6200000000000 + $this->skuCounter),
                        'size' => $size,
                        'color' => $color,
                        'price' => $price,
                        'cost' => $cost,
                        'is_active' => true,
                    ]));
                }
            }
        }

        return $variants;
    }

    /**
     * Opening stock, posted as a purchase movement — the only way stock is ever
     * changed.
     */
    protected function openingStock(\Illuminate\Support\Collection $variants, Branch $main, Branch $second, User $owner): void
    {
        foreach ($variants as $index => $variant) {
            // Varied on purpose, including a couple of last pieces.
            $mainQty = [8, 5, 3, 1, 12, 6][$index % 6];

            StockMovement::create([
                'branch_id' => $main->id,
                'variant_id' => $variant->id,
                'quantity' => $mainQty,
                'type' => StockMovementType::Purchase,
                'user_id' => $owner->id,
                'note' => 'رصيد افتتاحي',
                'occurred_at' => now()->subDays(10),
            ]);

            if ($index % 3 === 0) {
                StockMovement::create([
                    'branch_id' => $second->id,
                    'variant_id' => $variant->id,
                    'quantity' => 4,
                    'type' => StockMovementType::Purchase,
                    'user_id' => $owner->id,
                    'note' => 'رصيد افتتاحي',
                    'occurred_at' => now()->subDays(10),
                ]);
            }
        }
    }

    /** One completed sale of two lines, with its stock movements. */
    protected function sampleInvoice(\Illuminate\Support\Collection $variants, Branch $branch, Device $device, User $seller): Invoice
    {
        $lines = [
            [$variants->first(), 1],
            [$variants->get(4), 2],
        ];

        $subtotalMinor = 0;

        foreach ($lines as [$variant, $quantity]) {
            $subtotalMinor += Money::multiply(Money::toMinor($variant->price), $quantity);
        }

        $discountMinor = Money::toMinor('50.00');
        $issuedAt = now()->subDays(3);

        $invoice = Invoice::create([
            'branch_id' => $branch->id,
            'device_id' => $device->id,
            'user_id' => $seller->id,
            'number' => $device->code.'-000001',
            'customer_name' => 'عميل نقدي',
            'subtotal' => Money::toDecimal($subtotalMinor),
            'discount_amount' => Money::toDecimal($discountMinor),
            'total' => Money::toDecimal($subtotalMinor - $discountMinor),
            'payment_method' => PaymentMethod::Cash,
            'status' => InvoiceStatus::Completed,
            'issued_at' => $issuedAt,
            'synced_at' => $issuedAt,
        ]);

        foreach ($lines as [$variant, $quantity]) {
            $variant->loadMissing('product');

            InvoiceItem::create([
                'invoice_id' => $invoice->id,
                'variant_id' => $variant->id,
                'product_name' => $variant->product->name,
                'variant_label' => $variant->label(),
                'sku' => $variant->sku,
                'unit_price' => $variant->price,
                'quantity' => $quantity,
                'line_total' => Money::toDecimal(Money::multiply(Money::toMinor($variant->price), $quantity)),
            ]);

            StockMovement::create([
                'branch_id' => $branch->id,
                'variant_id' => $variant->id,
                'quantity' => -$quantity,
                'type' => StockMovementType::Sale,
                'reference_type' => $invoice->getMorphClass(),
                'reference_id' => $invoice->id,
                'user_id' => $seller->id,
                'device_id' => $device->id,
                'occurred_at' => $issuedAt,
            ]);
        }

        return $invoice;
    }

    /** One line of that invoice coming back, restocked and refunded in cash. */
    protected function sampleReturn(Invoice $invoice, Branch $branch, Device $device, User $manager): void
    {
        $item = $invoice->items()->first();
        $issuedAt = now()->subDay();

        $saleReturn = SaleReturn::create([
            'branch_id' => $branch->id,
            'device_id' => $device->id,
            'user_id' => $manager->id,
            'invoice_id' => $invoice->id,
            'number' => $device->code.'-R-000001',
            'refund_amount' => $item->unit_price,
            'refund_method' => RefundMethod::Cash,
            'reason' => 'المقاس مش مناسب',
            'issued_at' => $issuedAt,
            'synced_at' => $issuedAt,
        ]);

        SaleReturnItem::create([
            'sale_return_id' => $saleReturn->id,
            'invoice_item_id' => $item->id,
            'variant_id' => $item->variant_id,
            'quantity' => 1,
            'unit_price' => $item->unit_price,
            'restock' => true,
            'condition' => ItemCondition::Good,
        ]);

        // What stops the same piece coming back twice.
        $item->increment('returned_quantity');

        StockMovement::create([
            'branch_id' => $branch->id,
            'variant_id' => $item->variant_id,
            'quantity' => 1,
            'type' => StockMovementType::Return,
            'reference_type' => $saleReturn->getMorphClass(),
            'reference_id' => $saleReturn->id,
            'user_id' => $manager->id,
            'device_id' => $device->id,
            'occurred_at' => $issuedAt,
        ]);
    }
}
