<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ShiftResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $open = $this->closed_at === null;

        return [
            'id' => $this->id,
            'branch_id' => $this->branch_id,
            'device_id' => $this->device_id,
            'device_code' => $this->whenLoaded('device', fn () => $this->device?->code),
            'device_name' => $this->whenLoaded('device', fn () => $this->device?->name),
            'user_id' => $this->user_id,
            'opened_by' => $this->whenLoaded('opener', fn () => $this->opener?->name),
            'status' => $open ? 'open' : 'closed',
            'opening_float' => (string) $this->opening_float,
            'opened_at' => $this->opened_at->toIso8601String(),
            'closed_at' => $this->closed_at?->toIso8601String(),

            // An OPEN shift never carries what the drawer should hold: whoever
            // is about to count it must not be able to read the answer first.
            // These appear once, with the count, in the close response and on
            // every closed shift after.
            'close_kind' => $this->close_kind?->value,
            'closed_by' => $this->whenLoaded('closer', fn () => $this->closer?->name),
            'expected_cash' => $this->when(! $open, fn () => (string) $this->expected_cash),
            // Null for an automatic close: nobody counted, so there is nothing to compare.
            'counted_cash' => $this->when(! $open, fn () => $this->counted_cash === null ? null : (string) $this->counted_cash),
            'difference' => $this->when(! $open, fn () => $this->difference === null ? null : (string) $this->difference),
            'note' => $this->when(! $open, fn () => $this->note),
        ];
    }
}
