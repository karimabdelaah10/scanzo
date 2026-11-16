<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StockMovementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'variant_id' => $this->variant_id,
            'branch_id' => $this->branch_id,
            'quantity' => $this->quantity,
            'type' => $this->type->value,
            // What caused it: an invoice, a return, or nothing for a delivery.
            'reference_type' => $this->reference_type ? class_basename($this->reference_type) : null,
            'reference_id' => $this->reference_id,
            // Transfers: the id both halves share, and the branch on the other side.
            'transfer_id' => $this->reference_type === 'stock_transfer' ? $this->reference_id : null,
            'counterpart_branch_id' => $this->getAttribute('counterpart_branch_id'),
            'user_id' => $this->user_id,
            'device_id' => $this->device_id,
            // Who did what, readable on the "movements" screen. whenLoaded so a
            // caller that skipped the eager load gets nothing rather than a
            // lazy-loading exception.
            'product_name' => $this->whenLoaded('variant', fn () => $this->variant?->product?->name),
            'variant_label' => $this->whenLoaded('variant', fn () => $this->variant?->label()),
            'sku' => $this->whenLoaded('variant', fn () => $this->variant?->sku),
            'user_name' => $this->whenLoaded('user', fn () => $this->user?->name),
            'device_code' => $this->whenLoaded('device', fn () => $this->device?->code),
            'note' => $this->note,
            'occurred_at' => $this->occurred_at?->toIso8601String(),
        ];
    }
}
