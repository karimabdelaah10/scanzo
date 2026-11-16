<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StockTransferResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $lateAfter = $request->user()->loadMissing('business')->business->transferLateAfterDays();

        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'from_branch_id' => $this->from_branch_id,
            'to_branch_id' => $this->to_branch_id,
            'note' => $this->note,
            'sent_by' => $this->whenLoaded('sender', fn () => $this->sender?->name),
            'sent_at' => $this->sent_at?->toIso8601String(),
            'received_by' => $this->whenLoaded('receiver', fn () => $this->receiver?->name),
            'received_at' => $this->received_at?->toIso8601String(),
            'cancelled_by' => $this->whenLoaded('canceller', fn () => $this->canceller?->name),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            // True while any of the shortage is still unaccounted for.
            'has_shortage' => $this->has_shortage,
            // In transit longer than the shop's setting. The number is sent so the screen can say "more than N days".
            'is_late' => $this->isLate($lateAfter),
            'late_after_days' => $lateAfter,
            'settlements' => $this->whenLoaded('settlements', fn () => $this->settlements->map(fn ($s) => [
                'variant_id' => $s->variant_id,
                'branch_id' => $s->branch_id,
                'quantity' => $s->quantity,
                'reason' => $s->reason,
                'settled_by' => $s->settler?->name,
                'settled_at' => $s->settled_at?->toIso8601String(),
            ])->values()),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($line) => [
                'variant_id' => $line->variant_id,
                'sku' => $line->variant?->sku,
                'product_name' => $line->variant?->product?->name,
                'variant_label' => $line->variant?->label(),
                'quantity_sent' => $line->quantity_sent,
                // Null until the target confirms.
                'quantity_received' => $line->quantity_received,
                // What was short when it was received. It never goes down.
                'quantity_short' => $line->quantityShort(),
                'quantity_settled' => $line->quantity_settled,
                // What is still short: the part nobody has found yet.
                'quantity_open_short' => $line->quantityOpenShort(),
            ])->values()),
            'pieces_sent' => $this->whenLoaded('items', fn () => (int) $this->items->sum('quantity_sent')),
            'pieces_short' => $this->whenLoaded('items', fn () => (int) $this->items->sum(fn ($line) => $line->quantityOpenShort())),
            'pieces_settled' => $this->whenLoaded('items', fn () => (int) $this->items->sum('quantity_settled')),
        ];
    }
}
