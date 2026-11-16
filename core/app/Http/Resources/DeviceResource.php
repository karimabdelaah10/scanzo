<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DeviceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'branch_id' => $this->branch_id,
            'name' => $this->name,
            'code' => $this->code,
            'type' => $this->type->value,
            'is_active' => $this->is_active,
            'last_sync_at' => $this->last_sync_at?->toIso8601String(),
            // Invoices are numbered from this, so the shop can see which till
            // a number on a receipt came from.
            'invoice_prefix' => $this->code.'-',
        ];
    }
}
