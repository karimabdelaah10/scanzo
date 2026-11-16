<?php

namespace App\Actions\Selling;

use App\Enums\InvoiceStatus;
use App\Enums\StockMovementType;
use App\Exceptions\DomainException;
use App\Models\Invoice;
use App\Models\SaleReturn;
use App\Models\User;
use App\Services\StockLedger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Cancels an invoice: marks it voided and puts every piece back with new
 * `cancellation` movements, in one transaction.
 *
 * Nothing is deleted. The invoice keeps its number and its lines, and the
 * original sale movements stay in the ledger; the cancellation is a second
 * entry that cancels them out, so the history still shows both.
 */
class VoidInvoice
{
    public function __construct(protected StockLedger $stock) {}

    public function handle(User $user, Invoice $invoice, ?string $reason): Invoice
    {
        $user->loadMissing('business');
        $business = $user->business;

        if (! $business->status->canSell()) {
            throw DomainException::businessSuspended();
        }

        if (Gate::forUser($user)->denies('void-invoices')) {
            throw DomainException::notAllowedToVoid();
        }

        if (! $user->canAccessBranch($invoice->branch_id)) {
            throw DomainException::branchNotAllowed();
        }

        $reason = trim((string) $reason);

        if ($reason === '') {
            throw DomainException::voidReasonRequired();
        }

        return DB::transaction(function () use ($user, $invoice, $reason, $business) {
            // Re-read under a lock: two managers cancelling at once, or a return
            // being written at the same moment, must see each other's result.
            $locked = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === InvoiceStatus::Voided) {
                throw DomainException::invoiceAlreadyVoided($locked->number);
            }

            if (SaleReturn::query()->where('invoice_id', $locked->id)->exists()) {
                throw DomainException::invoiceHasReturns($locked->number);
            }

            if (SaleReturn::query()->where('exchange_invoice_id', $locked->id)->exists()) {
                throw DomainException::invoiceIsAnExchange($locked->number);
            }

            $windowHours = $business->voidWindowHours();

            if (! $locked->isWithinVoidWindow($windowHours)) {
                throw DomainException::outsideVoidWindow($locked->number, $windowHours);
            }

            $locked->forceFill([
                'status' => InvoiceStatus::Voided,
                'voided_at' => now(),
                'voided_by' => $user->id,
                'void_reason' => $reason,
            ])->save();

            foreach ($locked->items()->get() as $line) {
                // A variant deleted since the sale has nothing to put back to.
                if ($line->variant_id === null) {
                    continue;
                }

                $this->stock->post(
                    variant: $line->variant_id,
                    branchId: $locked->branch_id,
                    quantity: $line->quantity,
                    type: StockMovementType::Cancellation,
                    reference: $locked,
                    user: $user,
                    device: $locked->device_id,
                    note: $reason,
                );
            }

            return $locked;
        });
    }
}
