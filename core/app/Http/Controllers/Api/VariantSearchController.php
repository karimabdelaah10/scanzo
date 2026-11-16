<?php

namespace App\Http\Controllers\Api;

use App\Actions\Selling\SearchVariants;
use App\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Http\Requests\SearchVariantsRequest;
use App\Http\Resources\VariantScanResource;
use Illuminate\Http\JsonResponse;

class VariantSearchController extends Controller
{
    /**
     * Find variants by name, size or colour.
     *
     * GET /api/variants/search?q=قميص ازرق&branch_id=...&page=1
     *
     * Rows have the same shape as a scan, so the till adds one to the cart
     * exactly as it adds a scanned piece. `cost` is never in it.
     */
    public function __invoke(SearchVariantsRequest $request, SearchVariants $search): JsonResponse
    {
        $branchId = (string) $request->input('branch_id');

        if (! $request->user()->canAccessBranch($branchId)) {
            throw DomainException::branchNotAllowed();
        }

        ['variants' => $variants, 'available' => $available] = $search->handle(
            (string) $request->input('q'),
            $branchId,
            (int) $request->input('page', 1),
        );

        return response()->json([
            'data' => $variants->getCollection()
                ->map(fn ($variant) => (new VariantScanResource($variant, $available[$variant->id] ?? 0))->resolve($request))
                ->values(),
            'meta' => [
                'page' => $variants->currentPage(),
                'has_more' => $variants->hasMorePages(),
            ],
        ]);
    }
}
