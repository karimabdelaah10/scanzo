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
 * The receiving branch confirms what actually arrived.
 *
 * Only that branch's manager or the owner may do it: the sender confirming its
 * own shipment would make the confirmation mean nothing.
 *
 * Receiving LESS than was sent is allowed, and is the point. The target gets
 * what arrived; the difference stays on the line (`quantity_received` below
 * `quantity_sent`) and `has_shortage` is set, so it is a shortage on one
 * transfer between two named branches on a known date. It is neither added to
 * a balance nor written off. Receiving MORE is refused.
 */
class ReceiveTransfer
{
    public function __construct(protected StockLedger $stock) {}

    /** @param  array<int, array{variant_id: string, quantity: int}>  $items  one per line of the transfer */
    public function handle(User $user, string $transferId, array $items): StockTransfer
    {
        return DB::transaction(function () use ($user, $transferId, $items) {
            // Two people confirming at once: the second waits, then sees it is no longer in transit.
            $transfer = StockTransfer::query()->lockForUpdate()->findOrFail($transferId);

            if ($user->isSeller() || ! $user->canAccessBranch($transfer->to_branch_id)) {
                throw DomainException::transferReceiveNotAllowed();
            }

            if (! $transfer->isInTransit()) {
                throw DomainException::transferNotInTransit($transfer->status->value);
            }

            $transfer->load('items.variant');

            $received = [];
            foreach ($items as $item) {
                $received[$item['variant_id']] = ($received[$item['variant_id']] ?? null) === null
                    ? (int) $item['quantity']
                    : throw DomainException::transferReceiptLinesMismatch();
            }

            if (count($received) !== $transfer->items->count()
                || $transfer->items->contains(fn ($line) => ! array_key_exists($line->variant_id, $received))) {
                throw DomainException::transferReceiptLinesMismatch();
            }

            $short = false;

            foreach ($transfer->items as $line) {
                $quantity = $received[$line->variant_id];

                if ($quantity < 0) {
                    throw DomainException::invalidTransferQuantity();
                }

                if ($quantity > $line->quantity_sent) {
                    throw DomainException::transferReceivedOverSent($line->variant->sku, $line->quantity_sent, $quantity);
                }

                $line->update(['quantity_received' => $quantity]);
                $short = $short || $quantity < $line->quantity_sent;

                if ($quantity > 0) {
                    $this->stock->post(
                        variant: $line->variant,
                        branchId: $transfer->to_branch_id,
                        quantity: $quantity,
                        type: StockMovementType::TransferIn,
                        user: $user,
                        note: $transfer->note,
                        referenceType: TransferStock::REFERENCE_TYPE,
                        referenceId: $transfer->id,
                    );
                }
            }

            $transfer->update([
                'status' => TransferStatus::Received,
                'received_by' => $user->id,
                'received_at' => now(),
                'has_shortage' => $short,
            ]);

            return $transfer;
        });
    }
}
