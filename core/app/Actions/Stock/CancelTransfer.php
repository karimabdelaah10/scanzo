<?php

namespace App\Actions\Stock;

use App\Enums\StockMovementType;
use App\Enums\TransferStatus;
use App\Exceptions\DomainException;
use App\Models\StockTransfer;
use App\Models\User;
use App\Services\StockLedger;
use Illuminate\Support\Facades\DB;

/**
 * The sender takes the pieces back, while nobody has confirmed them.
 *
 * Once the target has confirmed, the pieces are on its shelf and this is
 * refused; moving them back is a new transfer in the other direction.
 */
class CancelTransfer
{
    public function __construct(protected StockLedger $stock) {}

    public function handle(User $user, string $transferId): StockTransfer
    {
        return DB::transaction(function () use ($user, $transferId) {
            // Locked, so a cancel racing a receipt has exactly one winner.
            $transfer = StockTransfer::query()->lockForUpdate()->findOrFail($transferId);

            if ($user->isSeller() || ! $user->canAccessBranch($transfer->from_branch_id)) {
                throw DomainException::transferCancelNotAllowed();
            }

            if (! $transfer->isInTransit()) {
                throw DomainException::transferNotInTransit($transfer->status->value);
            }

            $transfer->load('items.variant');

            foreach ($transfer->items as $line) {
                $this->stock->post(
                    variant: $line->variant,
                    branchId: $transfer->from_branch_id,
                    quantity: $line->quantity_sent,
                    type: StockMovementType::TransferCancel,
                    user: $user,
                    note: $transfer->note,
                    referenceType: TransferStock::REFERENCE_TYPE,
                    referenceId: $transfer->id,
                );
            }

            $transfer->update([
                'status' => TransferStatus::Cancelled,
                'cancelled_by' => $user->id,
                'cancelled_at' => now(),
            ]);

            return $transfer;
        });
    }
}
