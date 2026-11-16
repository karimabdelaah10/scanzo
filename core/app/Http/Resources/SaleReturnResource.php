<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SaleReturnResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'invoice_id' => $this->invoice_id,
            'exchange_invoice_id' => $this->exchange_invoice_id,
            'branch_id' => $this->branch_id,
            'device_id' => $this->device_id,
            'refund_amount' => (string) $this->refund_amount,
            'refund_method' => $this->refund_method->value,
            'reason' => $this->reason,
            'issued_at' => $this->issued_at?->toIso8601String(),
            'synced_at' => $this->synced_at?->toIso8601String(),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'id' => $item->id,
                'invoice_item_id' => $item->invoice_item_id,
                'variant_id' => $item->variant_id,
                'quantity' => $item->quantity,
                'unit_price' => (string) $item->unit_price,
                'line_total' => $item->lineTotal(),
                'amount' => (string) $item->amount,
                'restock' => $item->restock,
                'condition' => $item->condition?->value,
            ])),
        ];
    }
}
