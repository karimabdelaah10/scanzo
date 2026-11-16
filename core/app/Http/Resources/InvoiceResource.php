<?php

namespace App\Http\Resources;

use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

class InvoiceResource extends JsonResource
{
    private function canBeVoidedBy(Request $request): bool
    {
        $user = $request->user();

        return $user !== null
            && Gate::forUser($user)->allows('void-invoices')
            && $user->canAccessBranch($this->branch_id)
            && ! $this->isVoided()
            && ! $this->has_returns
            && ! $this->is_exchange
            && $this->relationLoaded('business')
            && $this->isWithinVoidWindow($this->business->voidWindowHours());
    }

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'branch_id' => $this->branch_id,
            'device_id' => $this->device_id,
            'customer_name' => $this->customer_name,
            'customer_phone' => $this->customer_phone,
            'subtotal' => (string) $this->subtotal,
            'discount_amount' => (string) $this->discount_amount,
            'total' => (string) $this->total,
            // The invoice's own, never the shop's current one.
            'currency' => $this->currency,
            'payment_method' => $this->payment_method->value,
            'payment_method_label' => $this->payment_method->label(),
            'status' => $this->status->value,
            'issued_at' => $this->issued_at?->toIso8601String(),
            'synced_at' => $this->synced_at?->toIso8601String(),
            'voided_at' => $this->voided_at?->toIso8601String(),
            'void_reason' => $this->void_reason,
            'voided_by_name' => $this->whenLoaded('voidedBy', fn () => $this->voidedBy?->name),
            // Only on a single invoice, where the controller also asked whether
            // it has returns or is an exchange. Answers "show the button?" for
            // the caller; the server re-checks everything when it is pressed.
            'can_void' => $this->whenHas('is_exchange', fn () => $this->canBeVoidedBy($request)),
            'void_window_hours' => $this->whenLoaded('business', fn () => $this->business->voidWindowHours()),
            // List only: the controller counts them inside the query.
            'items_count' => $this->whenHas('items_count', fn () => (int) $this->items_count),
            'has_returns' => $this->whenHas('has_returns', fn () => (bool) $this->has_returns),
            // The three below are only present when the controller loaded them:
            // a list of invoices does not need the shop's header on every row.
            'business' => $this->whenLoaded('business', fn () => [
                'name' => $this->business->name,
                'phone' => $this->business->phone,
                'tax_number' => $this->business->tax_number,
                'receipt_header' => $this->business->setting('receipt.header') ?: null,
                'receipt_footer' => $this->business->setting('receipt.footer') ?: null,
                'logo' => \App\Support\Brand\Brand::logoUrl($this->business),
                // Carried here so the receipt can state it without another request.
                'return_window_days' => $this->business->returnWindowDays(),
            ]),
            'branch' => $this->whenLoaded('branch', fn () => [
                'name' => $this->branch->name,
                'address' => $this->branch->address,
                'phone' => $this->branch->phone,
            ]),
            'seller' => $this->whenLoaded('seller', fn () => [
                'name' => $this->seller?->name,
            ]),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'id' => $item->id,
                'variant_id' => $item->variant_id,
                'product_name' => $item->product_name,
                'variant_label' => $item->variant_label,
                'sku' => $item->sku,
                'unit_price' => (string) $item->unit_price,
                // Taken off this line; line_total is already net of it, and is
                // what the customer paid (and what a return refunds).
                'discount_amount' => (string) $item->discount_amount,
                'quantity' => $item->quantity,
                'line_total' => (string) $item->line_total,
                // Its share of the invoice discount, and what was really paid
                // (line_total minus that share) — what a return refunds.
                'invoice_discount_share' => (string) $item->invoice_discount_share,
                'paid' => Money::toDecimal($item->paidMinor()),
                'returned_quantity' => $item->returned_quantity,
                'returnable_quantity' => $item->returnableQuantity(),
            ])),
        ];
    }
}
