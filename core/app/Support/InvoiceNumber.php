<?php

namespace App\Support;

use App\Models\Device;
use App\Models\Invoice;
use App\Models\SaleReturn;

/**
 * Human-readable document numbers: the device code, then a counter local to
 * that device — A1-000123, A1-R-000045.
 *
 * Per-device counters are what let a device number its own documents while
 * offline without ever colliding with another till. The database also carries
 * a unique index on (business_id, number), so a race fails loudly instead of
 * producing two invoices with the same number.
 */
class InvoiceNumber
{
    public const PADDING = 6;

    /** Must be called inside a transaction: it relies on the row lock. */
    public static function nextForInvoice(Device $device): string
    {
        return self::next(Invoice::query(), $device->code.'-');
    }

    /** Must be called inside a transaction. */
    public static function nextForReturn(Device $device): string
    {
        return self::next(SaleReturn::query(), $device->code.'-R-');
    }

    protected static function next(\Illuminate\Database\Eloquent\Builder $query, string $prefix): string
    {
        $last = $query
            ->where('number', 'like', $prefix.'%')
            ->lockForUpdate()
            ->orderByDesc('number')
            ->value('number');

        $sequence = $last === null
            ? 1
            : ((int) substr($last, strlen($prefix))) + 1;

        return $prefix.str_pad((string) $sequence, self::PADDING, '0', STR_PAD_LEFT);
    }
}
