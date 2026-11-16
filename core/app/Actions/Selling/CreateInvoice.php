<?php

namespace App\Actions\Selling;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Enums\StockMovementType;
use App\Exceptions\DomainException;
use App\Models\Device;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\StockLedger;
use App\Support\InvoiceNumber;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Closes a sale: one transaction that writes the invoice, its lines and the
 * stock movements together, or writes nothing.
 *
 * Prices are read from the catalogue here and never taken from the request —
 * the till may be running an old copy of the price list, and a client that can
 * name its own price is a shop that can be robbed.
 */
class CreateInvoice
{
    public function __construct(protected StockLedger $stock) {}

    /**
     * @param  array<int, array{variant_id: string, quantity: int, discount_amount?: string|int|float|null}>  $items
     *         `discount_amount` is the line discount as money (the till turns a typed 20% into an amount).
     */
    public function handle(
        User $seller,
        Device $device,
        array $items,
        string $discountAmount = '0.00',
        PaymentMethod $paymentMethod = PaymentMethod::Cash,
        ?string $customerName = null,
        ?string $customerPhone = null,
        ?\DateTimeInterface $issuedAt = null,
        ?string $invoiceId = null,
    ): Invoice {
        if ($items === []) {
            throw DomainException::emptyCart();
        }

        // Lazy loading is off outside production, so load what this needs.
        $seller->loadMissing('business.subscription');

        $business = $seller->business;

        if (! $business->status->canSell()) {
            throw DomainException::businessSuspended();
        }

        if (! $seller->canAccessBranch($device->branch_id)) {
            throw DomainException::branchNotAllowed();
        }

        // A device that stopped reaching the server long ago must not keep
        // selling, otherwise a shop runs on indefinitely without paying.
        $graceDays = (int) ($business->subscription?->offline_grace_days
            ?? config('scanzo.offline_grace_days'));

        if (! $device->isWithinOfflineGrace($graceDays)) {
            throw DomainException::deviceOutOfGrace($graceDays);
        }

        // A device may supply the id so that a retried sync cannot create the
        // same sale twice.
        if ($invoiceId !== null) {
            $existing = Invoice::find($invoiceId);

            if ($existing !== null) {
                return $existing;
            }
        }

        $branchId = $device->branch_id;
        $issuedAt ??= now();

        // Merge repeated scans of the same item into one line.
        $quantities = [];
        $lineDiscounts = [];

        foreach ($items as $item) {
            $variantId = $item['variant_id'];
            $quantities[$variantId] = ($quantities[$variantId] ?? 0) + (int) $item['quantity'];
            $lineDiscounts[$variantId] = ($lineDiscounts[$variantId] ?? 0)
                + max(0, Money::toMinor($item['discount_amount'] ?? 0));
        }

        // A discount is money the shop does not take, so asking for one is
        // checked before anything is read or written.
        // One setting covers both kinds of discount.
        if (array_sum($lineDiscounts) > 0 && ! $seller->canGiveDiscounts()) {
            throw DomainException::lineDiscountsNotAllowedForSellers();
        }

        if (Money::toMinor($discountAmount) > 0 && ! $seller->canGiveDiscounts()) {
            throw DomainException::invoiceDiscountNotAllowedForSellers();
        }

        return DB::transaction(function () use (
            $seller, $device, $branchId, $quantities, $lineDiscounts, $discountAmount,
            $paymentMethod, $customerName, $customerPhone, $issuedAt, $invoiceId
        ) {
            $variants = ProductVariant::query()
                ->with('product')
                ->whereIn('id', array_keys($quantities))
                ->get()
                ->keyBy('id');

            $balances = $this->stock->balances(array_keys($quantities), $branchId);

            // Totals are summed in piastres and only turned back into
            // decimal strings at the end.
            $subtotalMinor = 0;
            $grossTotalMinor = 0;
            $lines = [];

            foreach ($quantities as $variantId => $quantity) {
                $variant = $variants->get($variantId);

                if ($variant === null) {
                    throw DomainException::variantNotFound($variantId);
                }

                if (! $variant->is_active) {
                    throw DomainException::variantInactive($variant->sku);
                }

                $available = (int) ($balances[$variantId] ?? 0);

                if ($available < $quantity) {
                    throw DomainException::outOfStock($variant->sku, $quantity, $available);
                }

                // The price comes from the catalogue, not the request.
                $unitPriceMinor = Money::toMinor($variant->price);
                $grossMinor = Money::multiply($unitPriceMinor, $quantity);

                // ORDER OF DISCOUNTS: the line discount comes first and the
                // invoice discount second. `line_total` is already net of its
                // line discount, `subtotal` is the sum of those net lines, and
                // the invoice discount is taken off that subtotal. So a line is
                // never discounted twice by the same amount, and
                //   total = Σ(unit_price × qty − line discount) − invoice discount.
                // Refunds and reports read `line_total` as "what was paid".
                $lineDiscountMinor = $lineDiscounts[$variantId] ?? 0;

                if ($lineDiscountMinor > $grossMinor) {
                    throw DomainException::lineDiscountTooLarge(
                        $variant->sku,
                        Money::toDecimal($lineDiscountMinor),
                        Money::toDecimal($grossMinor),
                    );
                }

                // The cap is a percentage of the line; rounded half up like the
                // till rounds its percentage into an amount, so a discount typed
                // as exactly the maximum is never refused by a piastre.
                if ($seller->isSeller()
                    && $lineDiscountMinor > Money::portion($grossMinor * $seller->business->discountMaxPercent(), 1, 100)) {
                    throw DomainException::lineDiscountOverLimit($variant->sku, $seller->business->discountMaxPercent());
                }

                $lineTotalMinor = $grossMinor - $lineDiscountMinor;
                $subtotalMinor += $lineTotalMinor;
                $grossTotalMinor += $grossMinor;

                $lines[] = [
                    'variant' => $variant,
                    'quantity' => $quantity,
                    'unit_price' => Money::toDecimal($unitPriceMinor),
                    'discount_amount' => Money::toDecimal($lineDiscountMinor),
                    'line_total' => Money::toDecimal($lineTotalMinor),
                ];
            }

            $discountMinor = max(0, Money::toMinor($discountAmount));

            if ($discountMinor > $subtotalMinor) {
                throw DomainException::discountTooLarge(
                    Money::toDecimal($discountMinor),
                    Money::toDecimal($subtotalMinor),
                );
            }

            // The seller's cap is on the COMBINED discount: line discounts plus
            // the invoice discount, against the gross total before either.
            // Capping each on its own base let 20% off a line and 20% off the
            // invoice add up to more than 20%. Only sellers have a cap: a
            // manager deciding on 50% off is a commercial decision.
            $combinedMinor = ($grossTotalMinor - $subtotalMinor) + $discountMinor;

            if ($seller->isSeller()
                && $combinedMinor > Money::portion($grossTotalMinor * $seller->business->discountMaxPercent(), 1, 100)) {
                throw DomainException::invoiceDiscountOverLimit($seller->business->discountMaxPercent());
            }

            // Spread the invoice discount over the lines NOW and store each
            // share on its line. A return later refunds from these stored
            // numbers and never recomputes them, so the receipt stays true
            // whatever changes in this code afterwards.
            $shares = Money::allocate($discountMinor, array_map(fn ($l) => Money::toMinor($l['line_total']), $lines));

            foreach ($lines as $i => $line) {
                $lines[$i]['invoice_discount_share'] = Money::toDecimal($shares[$i]);
            }

            $invoice = new Invoice([
                'branch_id' => $branchId,
                'device_id' => $device->id,
                'user_id' => $seller->id,
                'number' => InvoiceNumber::nextForInvoice($device),
                'customer_name' => $customerName,
                'customer_phone' => $customerPhone,
                'subtotal' => Money::toDecimal($subtotalMinor),
                'discount_amount' => Money::toDecimal($discountMinor),
                'total' => Money::toDecimal($subtotalMinor - $discountMinor),
                // Snapshot, like the line prices: the shop's currency right now.
                'currency' => $seller->business->currency(),
                'payment_method' => $paymentMethod,
                'status' => InvoiceStatus::Completed,
                'issued_at' => $issuedAt,
                'synced_at' => now(),
            ]);

            if ($invoiceId !== null) {
                $invoice->id = $invoiceId;
            }

            $invoice->save();

            foreach ($lines as $line) {
                /** @var ProductVariant $variant */
                $variant = $line['variant'];

                // Snapshot: changing a price or deleting a variant later must
                // not rewrite this receipt.
                InvoiceItem::create([
                    'invoice_id' => $invoice->id,
                    'variant_id' => $variant->id,
                    'product_name' => $variant->product->name,
                    'variant_label' => $variant->label(),
                    'sku' => $variant->sku,
                    'unit_price' => $line['unit_price'],
                    'discount_amount' => $line['discount_amount'],
                    'invoice_discount_share' => $line['invoice_discount_share'],
                    'quantity' => $line['quantity'],
                    'line_total' => $line['line_total'],
                ]);

                $this->stock->post(
                    variant: $variant,
                    branchId: $branchId,
                    quantity: -$line['quantity'],
                    type: StockMovementType::Sale,
                    reference: $invoice,
                    user: $seller,
                    device: $device,
                    occurredAt: $issuedAt,
                );
            }

            $device->forceFill(['last_sync_at' => now()])->save();

            return $invoice->load('items');
        });
    }
}
