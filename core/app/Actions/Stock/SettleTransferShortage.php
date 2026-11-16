<?php

namespace App\Actions\Stock;

use App\Enums\StockMovementType;
use App\Enums\TransferStatus;
use App\Exceptions\DomainException;
use App\Models\StockTransfer;
use App\Models\StockTransferSettlement;
use App\Models\User;
use App\Services\StockLedger;
use Illuminate\Support\Facades\DB;

/**
 * Pieces reported short on a transfer turn up. They are put into the branch
 * they really are in: the target (arrived late) or the source (never left).
 *
 * A settlement MOVES the shortage into a branch; it does not delete it. The
 * line's `quantity_short` stays what it was, `quantity_settled` grows, and the
 * movement it writes is an ordinary ledger row. So per variant
 *
 *     source + target + in transit + OPEN shortage = constant
 *
 * still holds, with the open shortage falling by what the branch gained.
 *
 * Partial is allowed (3 short, 2 found). More than is still open is refused,
 * and so is a settlement without a reason: that text is the only record of why
 * the numbers changed.
 *
 * No time limit: a transfer closed months ago can still be settled. The reason
 * and the settlement row are the control, not a deadline.
 */
class SettleTransferShortage
{
    public function __construct(protected StockLedger $stock) {}

    /** @param  array<int, array{variant_id: string, quantity: int}>  $items */
    public function handle(User $user, string $transferId, string $branchId, string $reason, array $items): StockTransfer
    {
        $reason = trim($reason);

        return DB::transaction(function () use ($user, $transferId, $branchId, $reason, $items) {
            // Two people settling the same shortage: the second sees what is left.
            $transfer = StockTransfer::query()->lockForUpdate()->findOrFail($transferId);

            // The owner, or the manager of the branch the pieces enter.
            if ($user->isSeller() || ! $user->canAccessBranch($branchId)) {
                throw DomainException::transferSettleNotAllowed();
            }

            if (! in_array($branchId, [$transfer->from_branch_id, $transfer->to_branch_id], true)) {
                throw DomainException::transferSettleBranchInvalid();
            }

            if ($reason === '') {
                throw DomainException::transferSettleReasonRequired();
            }

            if ($transfer->status !== TransferStatus::Received || ! $transfer->has_shortage) {
                throw DomainException::transferSettleNothingOpen();
            }

            $transfer->load('items.variant');

            // One entry per line, summed, so the same variant twice cannot slip past the cap.
            $wanted = [];
            foreach ($items as $item) {
                $wanted[$item['variant_id']] = ($wanted[$item['variant_id']] ?? 0) + (int) $item['quantity'];
            }

            foreach ($wanted as $variantId => $quantity) {
                $line = $transfer->items->firstWhere('variant_id', $variantId);

                if ($line === null) {
                    throw DomainException::transferReceiptLinesMismatch();
                }

                if ($quantity < 1) {
                    throw DomainException::invalidTransferQuantity();
                }

                if ($quantity > $line->quantityOpenShort()) {
                    throw DomainException::transferSettleOverShort($line->variant->sku, $line->quantityOpenShort(), $quantity);
                }

                $line->update(['quantity_settled' => $line->quantity_settled + $quantity]);

                $this->stock->post(
                    variant: $line->variant,
                    branchId: $branchId,
                    quantity: $quantity,
                    type: StockMovementType::TransferSettlement,
                    user: $user,
                    note: $reason,
                    referenceType: TransferStock::REFERENCE_TYPE,
                    referenceId: $transfer->id,
                );

                StockTransferSettlement::create([
                    'transfer_id' => $transfer->id,
                    'variant_id' => $variantId,
                    'branch_id' => $branchId,
                    'quantity' => $quantity,
                    'reason' => $reason,
                    'settled_by' => $user->id,
                    'settled_at' => now(),
                ]);
            }

            $transfer->update([
                'has_shortage' => $transfer->items->contains(fn ($line) => $line->quantityOpenShort() > 0),
            ]);

            return $transfer;
        });
    }
}
