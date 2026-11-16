<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockTransferItem extends Model
{
    use BelongsToBusiness, HasFactory, HasUuids;

    protected $fillable = ['business_id', 'transfer_id', 'variant_id', 'quantity_sent', 'quantity_received', 'quantity_settled'];

    protected function casts(): array
    {
        return [
            'quantity_sent' => 'integer',
            'quantity_received' => 'integer',
            'quantity_settled' => 'integer',
        ];
    }

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(StockTransfer::class, 'transfer_id');
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    /** Sent and not received, once the target has confirmed. Zero before that. Never shrinks: it is what happened. */
    public function quantityShort(): int
    {
        return $this->quantity_received === null ? 0 : $this->quantity_sent - $this->quantity_received;
    }

    /** The part of the shortage nobody has accounted for yet. This is the "short" in the invariant. */
    public function quantityOpenShort(): int
    {
        return $this->quantityShort() - $this->quantity_settled;
    }
}
