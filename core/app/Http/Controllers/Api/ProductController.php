<?php

namespace App\Http\Controllers\Api;

use App\Actions\Catalogue\CreateProduct;
use App\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProductRequest;
use App\Http\Requests\UpdateProductRequest;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Services\StockLedger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

class ProductController extends Controller
{
    public function __construct(protected StockLedger $stock) {}

    /**
     * GET /api/products?search=&category_id=&branch_id=&active=
     *
     * `branch_id` adds each variant's stock in that branch. Without it the
     * listing runs no stock queries at all.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $products = Product::query()
            ->with(['variants', 'category'])
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = $request->string('search')->toString();

                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhereHas('variants', fn ($v) => $v
                            ->where('sku', 'like', "%{$search}%")
                            ->orWhere('barcode', 'like', "%{$search}%"));
                });
            })
            ->when($request->filled('category_id'), fn ($q) => $q->where('category_id', $request->input('category_id')))
            ->when($request->filled('active'), fn ($q) => $q->where('is_active', $request->boolean('active')))
            ->orderBy('name')
            ->paginate(25);

        $this->attachStock($products->getCollection(), $request->input('branch_id'));

        return ProductResource::collection($products);
    }

    /** POST /api/products — the product and its variants together. */
    public function store(StoreProductRequest $request, CreateProduct $createProduct): JsonResponse
    {
        if (Gate::denies('manage-catalogue')) {
            throw DomainException::notAllowedToManageCatalogue();
        }

        $product = $createProduct->handle(
            user: $request->user(),
            name: $request->input('name'),
            variants: $request->input('variants'),
            categoryId: $request->input('category_id'),
            description: $request->input('description'),
            openingStockBranchId: $request->input('opening_stock_branch_id'),
            productId: $request->input('id'),
        );

        return (new ProductResource($product->load('category')))->response()->setStatusCode(201);
    }

    /** GET /api/products/{product}?branch_id= */
    public function show(Request $request, string $product): ProductResource
    {
        $model = Product::with(['variants', 'category'])->findOrFail($product);

        $this->attachStock(collect([$model]), $request->input('branch_id'));

        return new ProductResource($model);
    }

    /** PATCH /api/products/{product} */
    public function update(UpdateProductRequest $request, string $product): ProductResource
    {
        if (Gate::denies('manage-catalogue')) {
            throw DomainException::notAllowedToManageCatalogue();
        }

        $model = Product::with(['variants', 'category'])->findOrFail($product);
        $model->update($request->validated());

        return new ProductResource($model);
    }

    /**
     * DELETE /api/products/{product} — a soft delete, so every invoice that
     * ever sold it still prints.
     */
    public function destroy(string $product): JsonResponse
    {
        if (Gate::denies('manage-catalogue')) {
            throw DomainException::notAllowedToManageCatalogue();
        }

        Product::findOrFail($product)->delete();

        return response()->json(status: 204);
    }

    /**
     * Put each variant's balance on it for the resource, in one query per
     * branch rather than one per variant.
     */
    protected function attachStock(Collection $products, ?string $branchId): void
    {
        if (blank($branchId)) {
            return;
        }

        $variants = $products->flatMap(fn (Product $product) => $product->variants);

        if ($variants->isEmpty()) {
            return;
        }

        $balances = $this->stock->balances($variants->pluck('id')->all(), $branchId);

        foreach ($variants as $variant) {
            $variant->setAttribute('stock', (int) ($balances[$variant->id] ?? 0));
        }
    }
}
