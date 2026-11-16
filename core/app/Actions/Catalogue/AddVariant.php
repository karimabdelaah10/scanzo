<?php

namespace App\Actions\Catalogue;

use App\Enums\StockMovementType;
use App\Exceptions\DomainException;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\StockLedger;
use App\Support\SkuGenerator;
use Illuminate\Support\Facades\DB;

/** A new size or colour of a product that already exists. */
class AddVariant
{
    public function __construct(protected StockLedger $stock) {}

    public function handle(
        User $user,
        Product $product,
        string|float $price,
        ?string $size = null,
        ?string $color = null,
        ?string $sku = null,
        ?string $barcode = null,
        string|float|null $cost = null,
        int $openingStock = 0,
        ?string $openingStockBranchId = null,
    ): ProductVariant {
        return DB::transaction(function () use (
            $user, $product, $price, $size, $color, $sku, $barcode, $cost,
            $openingStock, $openingStockBranchId
        ) {
            $sku = filled($sku) ? trim($sku) : SkuGenerator::next();

            if (ProductVariant::withTrashed()->where('sku', $sku)->exists()) {
                throw DomainException::duplicateSku($sku);
            }

            $variant = ProductVariant::create([
                'product_id' => $product->id,
                'sku' => $sku,
                'barcode' => filled($barcode) ? trim($barcode) : null,
                'size' => $size,
                'color' => $color,
                'price' => $price,
                'cost' => $cost,
                'is_active' => true,
            ]);

            if ($openingStock > 0 && $openingStockBranchId !== null) {
                $this->stock->post(
                    variant: $variant,
                    branchId: $openingStockBranchId,
                    quantity: $openingStock,
                    type: StockMovementType::Purchase,
                    user: $user,
                    note: 'رصيد افتتاحي',
                );
            }

            return $variant;
        });
    }
}
