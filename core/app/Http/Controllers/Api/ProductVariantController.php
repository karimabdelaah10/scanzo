<?php

namespace App\Http\Controllers\Api;

use App\Actions\Catalogue\AddVariant;
use App\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreVariantRequest;
use App\Http\Requests\UpdateVariantRequest;
use App\Http\Resources\ProductVariantResource;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\StockLedger;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class ProductVariantController extends Controller
{
    public function __construct(protected StockLedger $stock) {}

    /** POST /api/products/{product}/variants — a new size or colour. */
    public function store(StoreVariantRequest $request, string $product, AddVariant $addVariant): JsonResponse
    {
        if (Gate::denies('manage-catalogue')) {
            throw DomainException::notAllowedToManageCatalogue();
        }

        $model = Product::findOrFail($product);

        $variant = $addVariant->handle(
            user: $request->user(),
            product: $model,
            price: $request->input('price'),
            size: $request->input('size'),
            color: $request->input('color'),
            sku: $request->input('sku'),
            barcode: $request->input('barcode'),
            cost: $request->input('cost'),
            openingStock: (int) $request->input('opening_stock', 0),
            openingStockBranchId: $request->input('opening_stock_branch_id'),
        );

        return (new ProductVariantResource($variant))->response()->setStatusCode(201);
    }

    /** PATCH /api/variants/{variant} — price, cost, or take it off sale. */
    public function update(UpdateVariantRequest $request, string $variant): ProductVariantResource
    {
        if (Gate::denies('manage-catalogue')) {
            throw DomainException::notAllowedToManageCatalogue();
        }

        $model = ProductVariant::findOrFail($variant);

        // Changing the price here never touches an invoice already written:
        // every line carries its own copy of what it was sold for.
        $model->update($request->validated());

        return new ProductVariantResource($model);
    }

    /**
     * DELETE /api/variants/{variant}
     *
     * Refused while there are pieces on a shelf: something physically in the
     * shop should be taken off sale, not deleted. Past invoices are safe
     * either way, since each line keeps its own snapshot.
     */
    public function destroy(string $variant): JsonResponse
    {
        if (Gate::denies('manage-catalogue')) {
            throw DomainException::notAllowedToManageCatalogue();
        }

        $model = ProductVariant::findOrFail($variant);

        $onHand = (int) $model->stockMovements()->sum('quantity');

        if ($onHand > 0) {
            throw DomainException::variantStillInStock($model->sku, $onHand);
        }

        $model->delete();

        return response()->json(status: 204);
    }
}
