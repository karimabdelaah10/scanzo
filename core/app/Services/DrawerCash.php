<?php

namespace App\Services;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Enums\RefundMethod;
use App\Models\Invoice;
use App\Models\SaleReturn;
use App\Support\Money;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * What should be in a till's drawer, worked out from the day's paperwork.
 *
 * Only CASH is in the drawer. Card and wallet sales never touch it, so they do
 * not count. Everything is read by device and by time window, never by seller:
 * the drawer is shared by whoever stands at the till.
 *
 *   expected = opening float
 *            + cash sales issued in the window
 *            − cash invoices voided in the window        (the money went back)
 *            − cash refunds issued in the window
 *            − exchange credit applied against a cash invoice
 *
 * The last line is easy to miss. An exchange is a return (credit) plus a new
 * invoice at its FULL value (see ProcessExchange): a customer swapping a 450
 * shirt for a 500 one hands over 50, not 500. Counting the new invoice in full
 * would put 450 piastres-worth of cash in the drawer that was never there.
 *
 * Not modelled, on purpose: how the shop pays out when an exchange is worth
 * LESS than the credit and the new invoice was not cash, and cash taken from
 * the drawer for anything outside the system. Both show up as a difference,
 * which is what the feature is for.
 *
 * This is the same arithmetic as /api/invoices/summary (cash = the `cash`
 * payment method; cash refunds = `refund_method` cash) but over a time window
 * on one device rather than a calendar day, and with the netting above.
 */
class DrawerCash
{
    /** Window ends are inclusive: a sale in the same second as the close was made before it. */
    public function expectedMinor(string $deviceId, int $floatMinor, CarbonInterface $from, CarbonInterface $to): int
    {
        return $floatMinor
            + $this->cashSalesMinor($deviceId, $from, $to)
            - $this->cashVoidedMinor($deviceId, $from, $to)
            - $this->cashRefundedMinor($deviceId, $from, $to)
            - $this->exchangeCreditAgainstCashMinor($deviceId, $from, $to);
    }

    /**
     * The newest thing this till did since `$from`, or `$from` itself. Used to
     * date a shift nobody closed: its drawer stopped mattering at the last sale.
     */
    public function lastActivityAt(string $deviceId, CarbonInterface $from): CarbonInterface
    {
        $lastInvoice = Invoice::query()->where('device_id', $deviceId)->where('issued_at', '>=', $from)->max('issued_at');
        $lastReturn = SaleReturn::query()->where('device_id', $deviceId)->where('issued_at', '>=', $from)->max('issued_at');

        return collect([$from, $lastInvoice ? Carbon::parse($lastInvoice) : null, $lastReturn ? Carbon::parse($lastReturn) : null])
            ->filter()
            ->max();
    }

    private function cashSalesMinor(string $deviceId, CarbonInterface $from, CarbonInterface $to): int
    {
        // Voided invoices stay in: the cash did arrive. The void is taken back
        // out below, in the window where the money actually left.
        return Money::toMinor(
            Invoice::query()
                ->where('device_id', $deviceId)
                ->where('payment_method', PaymentMethod::Cash)
                ->whereBetween('issued_at', [$from, $to])
                ->sum('total'),
        );
    }

    private function cashVoidedMinor(string $deviceId, CarbonInterface $from, CarbonInterface $to): int
    {
        return Money::toMinor(
            Invoice::query()
                ->where('device_id', $deviceId)
                ->where('payment_method', PaymentMethod::Cash)
                ->where('status', InvoiceStatus::Voided)
                ->whereBetween('voided_at', [$from, $to])
                ->sum('total'),
        );
    }

    private function cashRefundedMinor(string $deviceId, CarbonInterface $from, CarbonInterface $to): int
    {
        return Money::toMinor(
            SaleReturn::query()
                ->where('device_id', $deviceId)
                ->where('refund_method', RefundMethod::Cash)
                ->whereBetween('issued_at', [$from, $to])
                ->sum('refund_amount'),
        );
    }

    private function exchangeCreditAgainstCashMinor(string $deviceId, CarbonInterface $from, CarbonInterface $to): int
    {
        return Money::toMinor(
            SaleReturn::query()
                ->where('device_id', $deviceId)
                ->where('refund_method', RefundMethod::Exchange)
                ->whereBetween('issued_at', [$from, $to])
                ->whereIn('exchange_invoice_id', Invoice::query()->select('id')->where('payment_method', PaymentMethod::Cash))
                ->sum('refund_amount'),
        );
    }
}
