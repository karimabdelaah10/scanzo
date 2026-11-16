<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One settlement of a transfer shortage: pieces found, and the branch they went into. */
class StockTransferSettlement extends Model
{
    use BelongsToBusiness, HasUuids;

    protected $fillable = [
        'business_id', 'transfer_id', 'variant_id', 'branch_id', 'quantity', 'reason', 'settled_by', 'settled_at',
    ];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'settled_at' => 'datetime'];
    }

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(StockTransfer::class, 'transfer_id');
    }

    public function settler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'settled_by');
    }
}
