<?php

namespace App\Http\Controllers\Api;

use App\Actions\Stock\AdjustStock;
use App\Actions\Stock\ReceiveStock;
use App\Actions\Stock\TransferStock;
use App\Enums\StockMovementType;
use App\Http\Requests\TransferStockRequest;
use App\Models\Branch;
use App\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Http\Requests\AdjustStockRequest;
use App\Http\Requests\ReceiveStockRequest;
use App\Http\Resources\StockMovementResource;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use App\Enums\TransferStatus;
use App\Services\StockLedger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class StockController extends Controller
{
    public function __construct(protected StockLedger $stock) {}

    /**
     * GET /api/stock?branch_id=&search=&low=5&negative=1
     *
     * What is on the shelf. `low` lists what has fallen to or below a number —
     * the one report a shop actually opens every morning. `negative` lists
     * what the ledger says went below zero, which is how overselling while
     * offline surfaces instead of hiding.
     */
    public function index(Request $request): JsonResponse
    {
        $branchId = (string) $request->input('branch_id');

        if (blank($branchId) || ! $request->user()->canAccessBranch($branchId)) {
            throw DomainException::branchNotAllowed();
        }

        // The balance is a column of the query, not something worked out per
        // page afterwards: `low` and `negative` must filter the whole
        // catalogue, or a shop's three nearly-gone items sit on page 7.
        $balances = StockMovement::query()
            ->selectRaw('variant_id, SUM(quantity) as qty')
            ->where('branch_id', $branchId)
            ->groupBy('variant_id');

        $byQuantity = $request->filled('low') || $request->boolean('negative');

        $variants = ProductVariant::query()
            ->with('product')
            ->leftJoinSub($balances, 'bal', 'bal.variant_id', '=', 'product_variants.id')
            ->selectRaw('product_variants.*, COALESCE(bal.qty, 0) as stock_quantity')
            ->when($request->filled('low'), fn ($q) => $q->whereRaw(
                'COALESCE(bal.qty, 0) <= ?', [(int) $request->input('low')]
            ))
            ->when($request->boolean('negative'), fn ($q) => $q->whereRaw('COALESCE(bal.qty, 0) < 0'))
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = $request->string('search')->toString();

                $query->where(function ($q) use ($search) {
                    $q->where('product_variants.sku', 'like', "%{$search}%")
                        ->orWhere('product_variants.barcode', 'like', "%{$search}%")
                        ->orWhereHas('product', fn ($p) => $p->where('name', 'like', "%{$search}%"));
                });
            })
            // Closest to running out first when looking at a shortage list.
            ->when($byQuantity, fn ($q) => $q->orderByRaw('COALESCE(bal.qty, 0) asc'))
            ->orderBy('product_variants.sku')
            ->paginate(50);

        // On its way TO this branch: sent, not yet confirmed. Its own column,
        // never added to `quantity`: this branch does not hold those pieces.
        $inTransit = StockTransferItem::query()
            ->selectRaw('stock_transfer_items.variant_id, SUM(stock_transfer_items.quantity_sent) as qty')
            ->join('stock_transfers', 'stock_transfers.id', '=', 'stock_transfer_items.transfer_id')
            ->where('stock_transfers.status', TransferStatus::InTransit)
            ->where('stock_transfers.to_branch_id', $branchId)
            ->whereIn('stock_transfer_items.variant_id', $variants->getCollection()->pluck('id'))
            ->groupBy('stock_transfer_items.variant_id')
            ->pluck('qty', 'variant_id');

        $rows = $variants->getCollection()
            ->map(fn (ProductVariant $variant) => [
                'in_transit_quantity' => (int) ($inTransit[$variant->id] ?? 0),
                'variant_id' => $variant->id,
                'sku' => $variant->sku,
                'product_name' => $variant->product->name,
                'label' => $variant->label(),
                'price' => (string) $variant->price,
                'is_active' => $variant->is_active,
                'quantity' => (int) $variant->stock_quantity,
            ])
            ->values();

        return response()->json([
            'data' => $rows,
            'meta' => [
                'branch_id' => $branchId,
                'current_page' => $variants->currentPage(),
                'last_page' => $variants->lastPage(),
                'total_variants' => $variants->total(),
            ],
        ]);
    }

    /** GET /api/stock/movements?variant_id=&branch_id=&type= — the audit trail. */
    public function movements(Request $request): AnonymousResourceCollection
    {
        $movements = StockMovement::query()
            ->when($request->filled('variant_id'), fn ($q) => $q->where('variant_id', $request->input('variant_id')))
            ->when($request->filled('branch_id'), fn ($q) => $q->where('branch_id', $request->input('branch_id')))
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->input('type')))
            ->unless(
                $request->user()->role->seesAllBranches(),
                fn ($q) => $q->where('branch_id', $request->user()->branch_id),
            )
            ->with(['variant.product', 'user:id,name', 'device:id,code'])
            ->latest('occurred_at')
            ->paginate(50);

        $this->attachTransferCounterparts($movements->getCollection());

        return StockMovementResource::collection($movements);
    }

    /**
     * For a transfer movement, which branch the other half of it is in — one
     * query for the page, so the movements screen can say "to Nasr" or
     * "from Maadi" without lazy loading.
     */
    protected function attachTransferCounterparts(\Illuminate\Support\Collection $movements): void
    {
        $transfers = $movements->where('reference_type', TransferStock::REFERENCE_TYPE);

        if ($transfers->isEmpty()) {
            return;
        }

        $byId = StockTransfer::query()
            ->whereIn('id', $transfers->pluck('reference_id')->unique())
            ->get(['id', 'from_branch_id', 'to_branch_id'])
            ->keyBy('id');

        foreach ($transfers as $movement) {
            $transfer = $byId->get($movement->reference_id);

            // The movement sits on one side of the transfer; the other side is the counterpart.
            $movement->setAttribute('counterpart_branch_id', match (true) {
                $transfer === null => null,
                $movement->branch_id === $transfer->from_branch_id => $transfer->to_branch_id,
                default => $transfer->from_branch_id,
            });
        }
    }

    /** POST /api/stock/receive — a delivery arrives. */
    public function receive(ReceiveStockRequest $request, ReceiveStock $receiveStock): AnonymousResourceCollection
    {
        if (Gate::denies('manage-stock')) {
            throw DomainException::notAllowedToManageStock();
        }

        $movements = $receiveStock->handle(
            user: $request->user(),
            branchId: $request->input('branch_id'),
            items: $request->input('items'),
            note: $request->input('note'),
        );

        return StockMovementResource::collection($movements);
    }

    /**
     * POST /api/stock/adjust — what the shelf was counted at.
     *
     * Send the count, not a difference: that is what the person holding the
     * pieces knows. The response says what the ledger thought, so the shop can
     * see where it was wrong.
     */
    public function adjust(AdjustStockRequest $request, AdjustStock $adjustStock): JsonResponse
    {
        if (Gate::denies('manage-stock')) {
            throw DomainException::notAllowedToManageStock();
        }

        $result = $adjustStock->handle(
            user: $request->user(),
            branchId: $request->input('branch_id'),
            items: $request->input('items'),
            note: $request->input('note'),
        );

        return response()->json([
            'data' => $result,
            'meta' => [
                'corrected' => $result->filter(fn (array $row) => $row['difference'] !== 0)->count(),
                'unchanged' => $result->filter(fn (array $row) => $row['difference'] === 0)->count(),
            ],
        ]);
    }
}
