<?php

namespace App\Actions\Returns;

use App\Enums\InvoiceStatus;
use App\Exceptions\DomainException;
use App\Models\Invoice;
use App\Models\User;

/**
 * What scanning a receipt resolves to.
 *
 * Every receipt carries a QR of its invoice number, so the same scanning
 * gesture that sells also starts a return. Searching by number stays as the
 * fallback for a receipt that was lost or is too creased to read.
 */
class LookupInvoiceForReturn
{
    /** @return array{invoice: Invoice, within_window: bool, window_days: int} */
    public function byNumber(string $number, User $user): array
    {
        $number = strtoupper(trim($number));

        $invoice = Invoice::query()
            ->with('items')
            ->where('number', $number)
            ->first();

        if ($invoice === null) {
            throw DomainException::invoiceNotFound($number);
        }

        if (! $user->canAccessBranch($invoice->branch_id)) {
            throw DomainException::branchNotAllowed();
        }

        if ($invoice->status === InvoiceStatus::Voided) {
            throw DomainException::invoiceVoided($invoice->number);
        }

        $user->loadMissing('business');
        $windowDays = $user->business->returnWindowDays();

        // Being outside the window is reported, not refused: a branch manager
        // may still accept it, and the till needs to know in order to ask.
        return [
            'invoice' => $invoice,
            'within_window' => $invoice->isWithinReturnWindow($windowDays),
            'window_days' => $windowDays,
        ];
    }
}
