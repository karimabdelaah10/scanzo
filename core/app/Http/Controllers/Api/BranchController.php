<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreBranchRequest;
use App\Http\Requests\UpdateBranchRequest;
use App\Http\Resources\BranchResource;
use App\Models\Branch;
use App\Models\Invoice;
use App\Models\Shift;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Services\SubscriptionLimits;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class BranchController extends Controller
{
    public function __construct(protected SubscriptionLimits $limits) {}

    /** GET /api/branches — a seller or manager sees only their own. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $branches = Branch::withCount(['users', 'devices'])
            ->unless(
                $request->user()->role->seesAllBranches(),
                fn ($q) => $q->where('id', $request->user()->branch_id),
            )
            ->orderBy('name')
            ->get();

        return BranchResource::collection($branches);
    }

    /** POST /api/branches */
    public function store(StoreBranchRequest $request): JsonResponse
    {
        $this->ownerOnly();

        $request->user()->loadMissing('business');
        $this->limits->assertCanAddBranch($request->user()->business);

        $branch = Branch::create($request->validated() + ['is_active' => true]);

        return (new BranchResource($branch))->response()->setStatusCode(201);
    }

    /** PATCH /api/branches/{branch} */
    public function update(UpdateBranchRequest $request, string $branch): BranchResource
    {
        $this->ownerOnly();

        $model = Branch::findOrFail($branch);
        $model->update($request->validated());

        return new BranchResource($model);
    }

    /**
     * DELETE /api/branches/{branch}
     *
     * Only a branch with no invoices and no stock movements can go. Any other
     * is switched off, because deleting it would take its invoices and stock
     * history with it.
     */
    public function destroy(string $branch): JsonResponse
    {
        $this->ownerOnly();

        $model = Branch::findOrFail($branch);

        if (Branch::count() <= 1) {
            throw DomainException::cannotRemoveLastBranch();
        }

        if (Invoice::where('branch_id', $model->id)->exists()) {
            throw DomainException::branchHasHistory($model->name);
        }

        // stock_movements.branch_id cascades, and stock exists only as that
        // ledger, so deleting here would erase the quantities too.
        if (Shift::where('branch_id', $model->id)->exists()) {
            throw DomainException::shiftsHaveHistory('branch', $model->name);
        }

        // A pending transfer to this branch has no movement here yet, so the
        // check below would miss it; the foreign key would refuse anyway.
        if (StockTransfer::where('from_branch_id', $model->id)->orWhere('to_branch_id', $model->id)->exists()) {
            throw DomainException::branchHasTransfers($model->name);
        }

        if (StockMovement::where('branch_id', $model->id)->exists()) {
            throw DomainException::branchHasStockHistory($model->name);
        }

        $model->delete();

        return response()->json(status: 204);
    }

    protected function ownerOnly(): void
    {
        if (Gate::denies('manage-business')) {
            throw DomainException::notAllowedToManageBusiness();
        }
    }
}
