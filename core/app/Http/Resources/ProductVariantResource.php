<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

class ProductVariantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sku' => $this->sku,
            'barcode' => $this->barcode,
            'size' => $this->size,
            'color' => $this->color,
            'label' => $this->label(),
            'price' => (string) $this->price,
            // The margin is for the owner and the branch manager, never the seller.
            'cost' => $this->when(Gate::allows('view-cost'), fn () => $this->cost === null ? null : (string) $this->cost),
            'is_active' => $this->is_active,
            // Set by the controller when the request named a branch; absent
            // otherwise, so a catalogue listing costs no stock queries.
            'stock' => $this->whenNotNull($this->resource->getAttribute('stock')),
        ];
    }
}
