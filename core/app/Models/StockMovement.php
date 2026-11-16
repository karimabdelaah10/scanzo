<?php

namespace App\Models;

use App\Enums\StockMovementType;
use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One line of the stock ledger. Append-only: never update or delete a movement,
 * post a correcting one instead.
 */
class StockMovement extends Model
{
    use BelongsToBusiness, HasFactory, HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = [
        'business_id', 'branch_id', 'variant_id', 'quantity', 'type',
        'reference_type', 'reference_id', 'user_id', 'device_id', 'note', 'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => StockMovementType::class,
            'quantity' => 'integer',
            'occurred_at' => 'datetime',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    /** The invoice or return that caused this movement. */
    public function reference(): MorphTo
    {
        return $this->morphTo();
    }
}
