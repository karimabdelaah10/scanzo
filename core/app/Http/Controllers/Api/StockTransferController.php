<?php

namespace App\Http\Controllers\Api;

use App\Actions\Stock\CancelTransfer;
use App\Actions\Stock\ReceiveTransfer;
use App\Actions\Stock\SettleTransferShortage;
use App\Http\Requests\SettleTransferShortageRequest;
use App\Actions\Stock\TransferStock;
use App\Enums\TransferStatus;
use App\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Http\Requests\ReceiveTransferRequest;
use App\Http\Requests\TransferStockRequest;
use App\Http\Resources\StockTransferResource;
use App\Models\Branch;
use App\Models\StockTransfer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Transfers between branches: send, confirm receipt, cancel.
 * See {@see TransferStock} for the model.
 */
class StockTransferController extends Controller
{
    private const RELATIONS = ['items.variant.product', 'sender:id,name', 'receiver:id,name', 'canceller:id,name', 'settlements.settler:id,name'];

    /**
     * GET /api/stock/transfers?box=&status=&shortage=1
     *
     * `box=incoming` is what the target must act on (what reached MY branch),
     * `box=outgoing` what my branch sent. A manager sees transfers that left
     * or reached their own branch; the owner sees all, and is the one who
     * asks for `shortage=1`.
     * `meta.awaiting` is how many in-transit transfers are waiting for that
     * branch to confirm: what the target must see first.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $own = $user->branch_id;

        $visible = StockTransfer::query()
            ->unless($user->role->seesAllBranches(), fn ($q) => $q->where(function ($q) use ($own) {
                $q->where('from_branch_id', $own)->orWhere('to_branch_id', $own);
            }));

        // "Mine" is the user's own branch; the owner has none, so they name the
        // branch they are looking at (`branch_id`) and the boxes are relative to it.
        $focus = $request->filled('branch_id') && $user->canAccessBranch((string) $request->input('branch_id'))
            ? (string) $request->input('branch_id')
            : $own;

        $lateCutoff = now()->subDays($user->loadMissing('business')->business->transferLateAfterDays());

        $transfers = (clone $visible)
            ->when($request->input('box') === 'incoming' && $focus, fn ($q) => $q->where('to_branch_id', $focus))
            ->when($request->input('box') === 'outgoing' && $focus, fn ($q) => $q->where('from_branch_id', $focus))
            ->when($request->filled('status') && TransferStatus::tryFrom((string) $request->input('status')), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->boolean('shortage'), fn ($q) => $q->where('has_shortage', true))
            ->when($request->boolean('late'), fn ($q) => $q
                ->where('status', TransferStatus::InTransit)
                ->where('sent_at', '<=', $lateCutoff))
            // What is waiting comes first, then newest.
            ->orderByRaw('status = ? desc', [TransferStatus::InTransit->value])
            ->latest('sent_at')
            ->with(self::RELATIONS)
            ->paginate(50);

        $awaiting = (clone $visible)
            ->where('status', TransferStatus::InTransit)
            ->when($focus, fn ($q) => $q->where('to_branch_id', $focus))
            ->count();

        // In transit past the shop's limit, anywhere the user can see. Shown to the owner as a warning.
        $late = (clone $visible)
            ->where('status', TransferStatus::InTransit)
            ->where('sent_at', '<=', $lateCutoff)
            ->count();

        return response()->json([
            'data' => StockTransferResource::collection($transfers->getCollection()),
            'meta' => [
                'current_page' => $transfers->currentPage(),
                'last_page' => $transfers->lastPage(),
                'awaiting' => $awaiting,
                'late' => $late,
                // A manager can send to any branch although their session lists only their own.
                'branches' => Branch::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            ],
        ]);
    }

    /** GET /api/stock/transfers/{transfer} */
    public function show(Request $request, string $transfer): StockTransferResource
    {
        $model = StockTransfer::query()->with(self::RELATIONS)->findOrFail($transfer);

        abort_unless(
            $request->user()->canAccessBranch($model->from_branch_id) || $request->user()->canAccessBranch($model->to_branch_id),
            403,
        );

        return new StockTransferResource($model);
    }

    /** POST /api/stock/transfers — send. The pieces leave the source and are in transit. */
    public function store(TransferStockRequest $request, TransferStock $transferStock): JsonResponse
    {
        if (Gate::denies('manage-stock')) {
            throw DomainException::notAllowedToManageStock();
        }

        $result = $transferStock->handle(
            user: $request->user(),
            fromBranchId: $request->input('from_branch_id'),
            toBranchId: $request->input('to_branch_id'),
            items: $request->input('items'),
            note: $request->input('note'),
        );

        return (new StockTransferResource($result['transfer']->load(self::RELATIONS)))
            ->response()
            ->setStatusCode(201);
    }

    /** POST /api/stock/transfers/{transfer}/receive — the target confirms what arrived. */
    public function receive(ReceiveTransferRequest $request, string $transfer, ReceiveTransfer $receiveTransfer): StockTransferResource
    {
        if (Gate::denies('manage-stock')) {
            throw DomainException::notAllowedToManageStock();
        }

        $model = $receiveTransfer->handle($request->user(), $transfer, $request->input('items'));

        return new StockTransferResource($model->load(self::RELATIONS));
    }

    /**
     * POST /api/stock/transfers/{transfer}/settle — missing pieces turned up.
     * Moves part of the shortage into the sending or receiving branch.
     */
    public function settle(SettleTransferShortageRequest $request, string $transfer, SettleTransferShortage $settle): StockTransferResource
    {
        if (Gate::denies('manage-stock')) {
            throw DomainException::notAllowedToManageStock();
        }

        $model = $settle->handle(
            $request->user(),
            $transfer,
            $request->input('branch_id'),
            $request->input('reason'),
            $request->input('items'),
        );

        return new StockTransferResource($model->load(self::RELATIONS));
    }

    /** POST /api/stock/transfers/{transfer}/cancel — the source takes it back, while it is in transit. */
    public function cancel(Request $request, string $transfer, CancelTransfer $cancelTransfer): StockTransferResource
    {
        if (Gate::denies('manage-stock')) {
            throw DomainException::notAllowedToManageStock();
        }

        $model = $cancelTransfer->handle($request->user(), $transfer);

        return new StockTransferResource($model->load(self::RELATIONS));
    }
}
