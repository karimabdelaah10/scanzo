<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCategoryRequest;
use App\Http\Resources\CategoryResource;
use App\Models\Category;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class CategoryController extends Controller
{
    /** GET /api/categories — top level with their children. */
    public function index(): AnonymousResourceCollection
    {
        $categories = Category::with('children')
            ->whereNull('parent_id')
            ->orderBy('name')
            ->get();

        return CategoryResource::collection($categories);
    }

    /** POST /api/categories */
    public function store(StoreCategoryRequest $request): JsonResponse
    {
        if (Gate::denies('manage-catalogue')) {
            throw DomainException::notAllowedToManageCatalogue();
        }

        $category = Category::create($request->validated());

        return (new CategoryResource($category))->response()->setStatusCode(201);
    }

    /** PATCH /api/categories/{category} */
    public function update(StoreCategoryRequest $request, string $category): CategoryResource
    {
        if (Gate::denies('manage-catalogue')) {
            throw DomainException::notAllowedToManageCatalogue();
        }

        $model = Category::findOrFail($category);
        $model->update($request->validated());

        return new CategoryResource($model);
    }

    /**
     * DELETE /api/categories/{category}
     *
     * Refused while the category has products or sub-categories. The foreign
     * keys are ON DELETE SET NULL, so letting it through would leave the
     * products uncategorised and lift the children to the top level, silently.
     * Archived products count too: they lose their category the same way, and
     * nobody would see it happen.
     */
    public function destroy(string $category): JsonResponse
    {
        if (Gate::denies('manage-catalogue')) {
            throw DomainException::notAllowedToManageCatalogue();
        }

        $model = Category::findOrFail($category);

        $children = $model->children()->count();

        if ($children > 0) {
            throw DomainException::categoryHasChildren($model->name, $children);
        }

        // Archived products are soft-deleted, so they are counted explicitly:
        // the foreign key would null their category just the same.
        $products = $model->products()->withTrashed()->count();

        if ($products > 0) {
            $archived = $model->products()->onlyTrashed()->count();

            throw DomainException::categoryHasProducts($model->name, $products, $archived);
        }

        $model->delete();

        return response()->json(status: 204);
    }
}
