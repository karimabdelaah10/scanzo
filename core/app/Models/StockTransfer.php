<?php

namespace App\Models;

use App\Enums\TransferStatus;
use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Stock on its way from one branch to another. Its id is the `reference_id` of
 * the stock movements it caused (reference_type `stock_transfer`).
 */
class StockTransfer extends Model
{
    use BelongsToBusiness, HasFactory, HasUuids;

    protected $fillable = [
        'business_id', 'from_branch_id', 'to_branch_id', 'status', 'note',
        'sent_by', 'sent_at', 'received_by', 'received_at', 'cancelled_by', 'cancelled_at',
        'has_shortage',
    ];

    protected function casts(): array
    {
        return [
            'status' => TransferStatus::class,
            'sent_at' => 'datetime',
            'received_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'has_shortage' => 'boolean',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(StockTransferItem::class, 'transfer_id');
    }

    public function settlements(): HasMany
    {
        return $this->hasMany(StockTransferSettlement::class, 'transfer_id');
    }

    /** In transit for longer than the shop's `transfers.late_after_days`. Read by the resource and the list. */
    public function isLate(int $afterDays): bool
    {
        return $this->isInTransit() && $this->sent_at->lte(now()->subDays($afterDays));
    }

    public function fromBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'from_branch_id');
    }

    public function toBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'to_branch_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function isInTransit(): bool
    {
        return $this->status === TransferStatus::InTransit;
    }
}
