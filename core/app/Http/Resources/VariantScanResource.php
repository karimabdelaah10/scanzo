<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * What the till shows after a scan.
 *
 * `cost` is never exposed: a seller must not see the margin.
 */
class VariantScanResource extends JsonResource
{
    public function __construct($resource, protected int $available = 0)
    {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sku' => $this->sku,
            'barcode' => $this->barcode,
            'product_name' => $this->product->name,
            'size' => $this->size,
            'color' => $this->color,
            'label' => $this->label(),
            'price' => (string) $this->price,
            'available' => $this->available,
        ];
    }
}
