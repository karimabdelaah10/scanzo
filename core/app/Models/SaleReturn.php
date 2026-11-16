<?php

namespace App\Models;

use App\Enums\RefundMethod;
use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Named SaleReturn because `Return` is not a legal PHP class name and
 * `returns` is a MySQL reserved word.
 *
 * An exchange is this plus a new invoice, linked by exchange_invoice_id.
 */
class SaleReturn extends Model
{
    use BelongsToBusiness, HasFactory, HasUuids;

    protected $fillable = [
        'business_id', 'branch_id', 'device_id', 'user_id',
        'invoice_id', 'exchange_invoice_id', 'number',
        'refund_amount', 'refund_method', 'reason', 'issued_at', 'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'refund_amount' => 'decimal:2',
            'refund_method' => RefundMethod::class,
            'issued_at' => 'datetime',
            'synced_at' => 'datetime',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** The replacement invoice, when this return is an exchange. */
    public function exchangeInvoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'exchange_invoice_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(SaleReturnItem::class);
    }

    public function stockMovements(): MorphMany
    {
        return $this->morphMany(StockMovement::class, 'reference');
    }

    public function isExchange(): bool
    {
        return $this->refund_method === RefundMethod::Exchange;
    }
}
