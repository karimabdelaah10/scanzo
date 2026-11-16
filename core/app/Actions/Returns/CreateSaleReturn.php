<?php

namespace App\Actions\Returns;

use App\Enums\InvoiceStatus;
use App\Enums\ItemCondition;
use App\Enums\RefundMethod;
use App\Enums\StockMovementType;
use App\Exceptions\DomainException;
use App\Models\Device;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\SaleReturn;
use App\Models\SaleReturnItem;
use App\Models\User;
use App\Services\StockLedger;
use App\Support\InvoiceNumber;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Takes goods back: one transaction that writes the return, its lines, the
 * stock that goes back on the shelf, and the returned_quantity on the original
 * invoice — or writes nothing.
 *
 * An exchange is this plus a new invoice. The new invoice records the full
 * value of what was sold and the return records the credit; the difference the
 * customer pays or receives is worked out for the screen and never stored, so
 * neither document lies about what happened.
 */
class CreateSaleReturn
{
    public function __construct(protected StockLedger $stock) {}

    /**
     * @param  array<int, array{invoice_item_id: string, quantity: int, condition?: string|null, restock?: bool|null}>  $items
     */
    public function handle(
        User $user,
        Device $device,
        Invoice $invoice,
        array $items,
        RefundMethod $refundMethod = RefundMethod::Cash,
        ?string $reason = null,
        bool $overrideWindow = false,
        ?Invoice $exchangeInvoice = null,
        ?\DateTimeInterface $issuedAt = null,
        ?string $returnId = null,
    ): SaleReturn {
        if ($items === []) {
            throw DomainException::emptyReturn();
        }

        $user->loadMissing('business');
        $business = $user->business;

        if (! $business->status->canSell()) {
            throw DomainException::businessSuspended();
        }

        if (! $user->canIssueReturns()) {
            throw DomainException::returnsNotAllowedForSellers();
        }

        if (! $user->canAccessBranch($invoice->branch_id)) {
            throw DomainException::branchNotAllowed();
        }

        if ($invoice->status === InvoiceStatus::Voided) {
            throw DomainException::invoiceVoided($invoice->number);
        }

        $windowDays = $business->returnWindowDays();

        if (! $invoice->isWithinReturnWindow($windowDays)) {
            // Past the window a branch manager may still accept it, and only
            // a branch manager may.
            if (! $overrideWindow) {
                throw DomainException::outsideReturnWindow($invoice->number, $windowDays);
            }

            if ($user->isSeller()) {
                throw DomainException::windowOverrideNotAllowed();
            }
        }

        // Device-supplied id, so a retried sync cannot refund twice.
        if ($returnId !== null) {
            $existing = SaleReturn::find($returnId);

            if ($existing !== null) {
                return $existing;
            }
        }

        $issuedAt ??= now();

        // Merge repeated lines for the same invoice item.
        $quantities = [];
        $conditions = [];
        $restocks = [];

        foreach ($items as $item) {
            $id = $item['invoice_item_id'];
            $quantities[$id] = ($quantities[$id] ?? 0) + (int) $item['quantity'];
            $conditions[$id] = $item['condition'] ?? $conditions[$id] ?? null;

            if (array_key_exists('restock', $item) && $item['restock'] !== null) {
                $restocks[$id] = (bool) $item['restock'];
            }
        }

        return DB::transaction(function () use (
            $user, $device, $invoice, $quantities, $conditions, $restocks,
            $refundMethod, $reason, $exchangeInvoice, $issuedAt, $returnId
        ) {
            // Locked for the length of the transaction: two tills returning
            // the same line at once must not both pass the remaining-quantity
            // check.
            $lines = InvoiceItem::query()
                ->where('invoice_id', $invoice->id)
                ->whereIn('id', array_keys($quantities))
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $refundMinor = 0;
            $planned = [];

            foreach ($quantities as $invoiceItemId => $quantity) {
                /** @var InvoiceItem|null $line */
                $line = $lines->get($invoiceItemId);

                if ($line === null) {
                    throw DomainException::lineNotOnInvoice($invoiceItemId);
                }

                $remaining = $line->returnableQuantity();

                if ($quantity > $remaining) {
                    throw DomainException::alreadyReturned($line->sku, $quantity, $remaining);
                }

                $condition = ItemCondition::tryFrom((string) $conditions[$invoiceItemId]);

                // A damaged piece does not go back on the shelf unless someone
                // says otherwise.
                $restock = $restocks[$invoiceItemId]
                    ?? ($condition === null || $condition->restockable());

                // Refunded what the customer PAID for these pieces: their share
                // of line_total minus the line's stored share of the invoice
                // discount (both snapshots from the sale, neither recomputed).
                // Using unit_price here would give back the discounts too — a
                // 450 shirt sold for 350 would refund 450, and the shop loses
                // 100 on every discounted return. Never today's price either.
                $amountMinor = $line->refundMinorFor($quantity);
                $refundMinor += $amountMinor;

                $planned[] = [
                    'line' => $line,
                    'quantity' => $quantity,
                    'unit_price' => Money::toDecimal(Money::toMinor($line->unit_price)),
                    'amount' => Money::toDecimal($amountMinor),
                    'condition' => $condition,
                    'restock' => $restock,
                ];
            }

            $saleReturn = new SaleReturn([
                'branch_id' => $invoice->branch_id,
                'device_id' => $device->id,
                'user_id' => $user->id,
                'invoice_id' => $invoice->id,
                'exchange_invoice_id' => $exchangeInvoice?->id,
                'number' => InvoiceNumber::nextForReturn($device),
                'refund_amount' => Money::toDecimal($refundMinor),
                'refund_method' => $refundMethod,
                'reason' => $reason,
                'issued_at' => $issuedAt,
                'synced_at' => now(),
            ]);

            if ($returnId !== null) {
                $saleReturn->id = $returnId;
            }

            $saleReturn->save();

            foreach ($planned as $plan) {
                /** @var InvoiceItem $line */
                $line = $plan['line'];

                SaleReturnItem::create([
                    'sale_return_id' => $saleReturn->id,
                    'invoice_item_id' => $line->id,
                    'variant_id' => $line->variant_id,
                    'quantity' => $plan['quantity'],
                    'unit_price' => $plan['unit_price'],
                    'amount' => $plan['amount'],
                    'restock' => $plan['restock'],
                    'condition' => $plan['condition'],
                ]);

                // The counter that stops the same piece coming back twice.
                $line->increment('returned_quantity', $plan['quantity']);

                // A variant deleted since the sale has nothing to restock to,
                // and a damaged piece is not put back.
                if ($plan['restock'] && $line->variant_id !== null) {
                    $this->stock->post(
                        variant: $line->variant_id,
                        branchId: $invoice->branch_id,
                        quantity: $plan['quantity'],
                        type: StockMovementType::Return,
                        reference: $saleReturn,
                        user: $user,
                        device: $device,
                        occurredAt: $issuedAt,
                    );
                }
            }

            $device->forceFill(['last_sync_at' => now()])->save();

            return $saleReturn->load('items');
        });
    }
}
