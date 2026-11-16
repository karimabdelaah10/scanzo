<?php

namespace App\Actions\Selling;

use App\Exceptions\DomainException;
use App\Models\ProductVariant;
use App\Services\StockLedger;
use App\Support\SkuGenerator;

/**
 * What a scan resolves to. The label carries only a code, so everything the
 * till shows — name, price, what is left — is read here, which is why a price
 * change never means reprinting the shop.
 */
class LookupVariant
{
    public function __construct(protected StockLedger $stock) {}

    /** @return array{variant: ProductVariant, available: int} */
    public function byCode(string $code, string $branchId): array
    {
        $code = trim($code);

        $variant = $this->find($code);

        // A code of digits alone is most likely a manufacturer barcode, so
        // that is tried first. Only when nothing matches is it read as the
        // number part of a generated SKU — which is what someone typing from
        // a scuffed label gives you, and what a USB scanner configured to
        // strip the prefix sends.
        if ($variant === null && preg_match('/^\d+$/', $code) === 1) {
            $variant = $this->find(SkuGenerator::PREFIX.$code);
        }

        if ($variant === null) {
            throw DomainException::variantNotFound($code);
        }

        if (! $variant->is_active) {
            throw DomainException::variantInactive($variant->sku);
        }

        return [
            'variant' => $variant,
            'available' => $this->stock->balance($variant->id, $branchId),
        ];
    }

    protected function find(string $code): ?ProductVariant
    {
        return ProductVariant::query()
            ->with('product')
            ->where(fn ($query) => $query->where('sku', $code)->orWhere('barcode', $code))
            ->first();
    }
}
