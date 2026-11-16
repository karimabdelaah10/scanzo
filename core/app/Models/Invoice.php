<?php

namespace App\Models;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Invoice extends Model
{
    use BelongsToBusiness, HasFactory, HasUuids;

    protected $fillable = [
        'business_id', 'branch_id', 'device_id', 'user_id', 'number',
        'customer_name', 'customer_phone',
        'subtotal', 'discount_amount', 'total', 'currency',
        'payment_method', 'status', 'issued_at', 'synced_at',
        'voided_at', 'voided_by', 'void_reason',
    ];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'payment_method' => PaymentMethod::class,
            'status' => InvoiceStatus::class,
            'issued_at' => 'datetime',
            'synced_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    /** Returns for which this invoice is the new sale of an exchange. */
    public function exchangeOf(): HasMany
    {
        return $this->hasMany(SaleReturn::class, 'exchange_invoice_id');
    }

    /** Still inside the business's void window, measured from when it was issued. */
    public function isWithinVoidWindow(int $windowHours): bool
    {
        return $this->issued_at->copy()->addHours($windowHours)->isFuture();
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

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function saleReturns(): HasMany
    {
        return $this->hasMany(SaleReturn::class);
    }

    public function stockMovements(): MorphMany
    {
        return $this->morphMany(StockMovement::class, 'reference');
    }

    public function isVoided(): bool
    {
        return $this->status === InvoiceStatus::Voided;
    }

    /** Still only on the device that issued it. */
    public function isPendingSync(): bool
    {
        return $this->synced_at === null;
    }

    /** Whether this invoice is still inside the business's return window. */
    public function isWithinReturnWindow(int $windowDays): bool
    {
        return $this->issued_at->copy()->addDays($windowDays)->isFuture();
    }
}
