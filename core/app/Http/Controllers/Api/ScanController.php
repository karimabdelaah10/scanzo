<?php

namespace App\Http\Controllers\Api;

use App\Actions\Selling\LookupVariant;
use App\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Http\Requests\ScanRequest;
use App\Http\Resources\VariantScanResource;

class ScanController extends Controller
{
    /**
     * Resolve a scanned code to an item and what is left of it in this branch.
     *
     * GET /api/scan?code=SKU-10452&branch_id=...
     */
    public function __invoke(ScanRequest $request, LookupVariant $lookup): VariantScanResource
    {
        $branchId = (string) $request->input('branch_id');

        if (! $request->user()->canAccessBranch($branchId)) {
            throw DomainException::branchNotAllowed();
        }

        ['variant' => $variant, 'available' => $available] = $lookup->byCode(
            (string) $request->input('code'),
            $branchId,
        );

        return new VariantScanResource($variant, $available);
    }
}
