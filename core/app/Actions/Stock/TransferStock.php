<?php

namespace App\Actions\Stock;

use App\Enums\StockMovementType;
use App\Enums\TransferStatus;
use App\Exceptions\DomainException;
use App\Models\Branch;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use App\Models\User;
use App\Services\StockLedger;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Sending stock to another branch.
 *
 * The pieces leave the source at once (one `transfer_out` per variant) and are
 * then IN TRANSIT: in no branch's balance, on a `stock_transfers` row waiting
 * for the target to confirm. They enter the target only in
 * {@see ReceiveTransfer}, or go back to the source in {@see CancelTransfer}.
 *
 * Per variant, source + target + in transit + short stays constant at every
 * step. The movements carry the transfer's id as `reference_id`.
 */
class TransferStock
{
    public const REFERENCE_TYPE = 'stock_transfer';

    public function __construct(protected StockLedger $stock) {}

    /**
     * @param  array<int, array{variant_id: string, quantity: int}>  $items
     * @return array{transfer: StockTransfer, movements: Collection<int, StockMovement>}
     */
    public function handle(User $user, string $fromBranchId, string $toBranchId, array $items, ?string $note = null): array
    {
        if ($items === []) {
            throw DomainException::emptyCart();
        }

        if ($fromBranchId === $toBranchId) {
            throw DomainException::transferToSameBranch();
        }

        // A manager sends from their own branch only. The owner sends from any.
        if (! $user->canAccessBranch($fromBranchId)) {
            throw DomainException::transferFromOtherBranch();
        }

        // Tenant-scoped, so another shop's branch is "not found", never a target.
        Branch::query()->findOrFail($toBranchId);

        return DB::transaction(function () use ($user, $fromBranchId, $toBranchId, $items, $note) {
            $quantities = [];

            foreach ($items as $item) {
                $quantity = (int) $item['quantity'];

                if ($quantity <= 0) {
                    throw DomainException::invalidTransferQuantity();
                }

                $quantities[$item['variant_id']] = ($quantities[$item['variant_id']] ?? 0) + $quantity;
            }

            // Locking the variant rows makes two transfers of the same piece
            // wait for each other, so they cannot both read the same balance.
            $variants = ProductVariant::query()
                ->whereIn('id', array_keys($quantities))
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $balances = $this->stock->balances(array_keys($quantities), $fromBranchId);

            $transfer = StockTransfer::create([
                'from_branch_id' => $fromBranchId,
                'to_branch_id' => $toBranchId,
                'status' => TransferStatus::InTransit,
                'note' => $note,
                'sent_by' => $user->id,
                'sent_at' => now(),
            ]);

            $movements = collect();

            foreach ($quantities as $variantId => $quantity) {
                $variant = $variants->get($variantId) ?? throw DomainException::variantNotFound($variantId);
                $available = (int) ($balances[$variantId] ?? 0);

                if ($quantity > $available) {
                    throw DomainException::outOfStock($variant->sku, $quantity, $available);
                }

                StockTransferItem::create([
                    'transfer_id' => $transfer->id,
                    'variant_id' => $variantId,
                    'quantity_sent' => $quantity,
                ]);

                $movements->push($this->stock->post(
                    variant: $variant,
                    branchId: $fromBranchId,
                    quantity: -$quantity,
                    type: StockMovementType::TransferOut,
                    user: $user,
                    note: $note,
                    referenceType: self::REFERENCE_TYPE,
                    referenceId: $transfer->id,
                ));
            }

            return ['transfer' => $transfer, 'movements' => $movements];
        });
    }
}
