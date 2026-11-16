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

/**
 * A product and its variants in one go, because a product with no size or
 * colour cannot be labelled or sold — so the till would never see it.
 *
 * Codes are generated when the shop does not supply its own, which is the
 * normal case: nobody types SKUs for four hundred pieces.
 */
class CreateProduct
{
    public function __construct(protected StockLedger $stock) {}

    /**
     * @param  array<int, array{size?: string|null, color?: string|null, sku?: string|null, barcode?: string|null, price: string|float, cost?: string|float|null, opening_stock?: int|null}>  $variants
     */
    public function handle(
        User $user,
        string $name,
        array $variants,
        ?string $categoryId = null,
        ?string $description = null,
        ?string $openingStockBranchId = null,
        ?string $productId = null,
    ): Product {
        // A device may supply the id so that a retry after a dropped
        // connection cannot create the same product twice. Already there:
        // hand it back untouched, no second set of variants or movements.
        if ($productId !== null && $existing = Product::find($productId)) {
            return $existing->load('variants');
        }

        if ($variants === []) {
            throw DomainException::productNeedsAVariant();
        }

        return DB::transaction(function () use (
            $user, $name, $variants, $categoryId, $description, $openingStockBranchId, $productId
        ) {
            $product = new Product([
                'category_id' => $categoryId,
                'name' => $name,
                'description' => $description,
                'is_active' => true,
            ]);

            if ($productId !== null) {
                $product->id = $productId;
            }

            $product->save();

            // One run of codes for the whole batch rather than a query each.
            $needed = count(array_filter(
                $variants,
                fn (array $variant) => blank($variant['sku'] ?? null),
            ));

            $generated = $needed > 0 ? SkuGenerator::take($needed) : [];
            $g = 0;

            foreach ($variants as $input) {
                $sku = filled($input['sku'] ?? null)
                    ? trim($input['sku'])
                    : $generated[$g++];

                if (ProductVariant::withTrashed()->where('sku', $sku)->exists()) {
                    throw DomainException::duplicateSku($sku);
                }

                $variant = ProductVariant::create([
                    'product_id' => $product->id,
                    'sku' => $sku,
                    'barcode' => filled($input['barcode'] ?? null) ? trim($input['barcode']) : null,
                    'size' => $input['size'] ?? null,
                    'color' => $input['color'] ?? null,
                    'price' => $input['price'],
                    'cost' => $input['cost'] ?? null,
                    'is_active' => true,
                ]);

                $opening = (int) ($input['opening_stock'] ?? 0);

                // Opening stock is a purchase movement like any other: the
                // ledger is the only way stock ever changes.
                if ($opening > 0 && $openingStockBranchId !== null) {
                    $this->stock->post(
                        variant: $variant,
                        branchId: $openingStockBranchId,
                        quantity: $opening,
                        type: StockMovementType::Purchase,
                        user: $user,
                        note: 'رصيد افتتاحي',
                    );
                }
            }

            return $product->load('variants');
        });
    }
}
