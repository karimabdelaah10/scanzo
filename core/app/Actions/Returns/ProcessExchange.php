<?php

namespace App\Actions\Returns;

use App\Actions\Selling\CreateInvoice;
use App\Enums\PaymentMethod;
use App\Enums\RefundMethod;
use App\Models\Device;
use App\Models\Invoice;
use App\Models\SaleReturn;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * An exchange: goods come back and others go out, in one transaction.
 *
 * It is modelled as a return plus a new invoice rather than as a third kind of
 * transaction, so the catalogue, the stock ledger and the reports only ever
 * have two shapes to understand. The new invoice carries the full value of
 * what was sold; the return carries the credit. The difference is what the
 * customer pays or receives, and it is computed for the screen rather than
 * stored, because storing it would make one of the two documents wrong.
 */
class ProcessExchange
{
    public function __construct(
        protected CreateInvoice $createInvoice,
        protected CreateSaleReturn $createSaleReturn,
    ) {}

    /**
     * @param  array<int, array{invoice_item_id: string, quantity: int, condition?: string|null, restock?: bool|null}>  $returnItems
     * @param  array<int, array{variant_id: string, quantity: int}>  $newItems
     * @return array{sale_return: SaleReturn, invoice: Invoice, difference: string, customer_pays: bool}
     */
    public function handle(
        User $user,
        Device $device,
        Invoice $originalInvoice,
        array $returnItems,
        array $newItems,
        PaymentMethod $paymentMethod = PaymentMethod::Cash,
        ?string $reason = null,
        bool $overrideWindow = false,
        ?\DateTimeInterface $issuedAt = null,
    ): array {
        return DB::transaction(function () use (
            $user, $device, $originalInvoice, $returnItems, $newItems,
            $paymentMethod, $reason, $overrideWindow, $issuedAt
        ) {
            // The sale first, so the return has something to point at. Its own
            // stock check runs here, so an exchange for something out of stock
            // fails before anything is refunded.
            $invoice = $this->createInvoice->handle(
                seller: $user,
                device: $device,
                items: $newItems,
                paymentMethod: $paymentMethod,
                issuedAt: $issuedAt,
            );

            $saleReturn = $this->createSaleReturn->handle(
                user: $user,
                device: $device,
                invoice: $originalInvoice,
                items: $returnItems,
                refundMethod: RefundMethod::Exchange,
                reason: $reason,
                overrideWindow: $overrideWindow,
                exchangeInvoice: $invoice,
                issuedAt: $issuedAt,
            );

            $differenceMinor = Money::toMinor($invoice->total) - Money::toMinor($saleReturn->refund_amount);

            return [
                'sale_return' => $saleReturn,
                'invoice' => $invoice,
                'difference' => Money::toDecimal(abs($differenceMinor)),
                // True: the customer owes the difference. False: we owe them.
                'customer_pays' => $differenceMinor >= 0,
            ];
        });
    }
}
