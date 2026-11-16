<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * What a QR label points at: one size and colour of a product, with its own
 * price and its own stock level.
 */
class ProductVariant extends Model
{
    use BelongsToBusiness, HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'business_id', 'product_id', 'sku', 'barcode',
        'size', 'color', 'price', 'cost', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'cost' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class, 'variant_id');
    }

    /** "L · أزرق" — what the till and the receipt show under the name. */
    public function label(): string
    {
        return collect([$this->size, $this->color])->filter()->implode(' · ');
    }

    /**
     * Stock is the sum of the ledger, never a stored column.
     * It can be negative when devices oversold while offline.
     */
    public function stockIn(string $branchId): int
    {
        return (int) $this->stockMovements()
            ->where('branch_id', $branchId)
            ->sum('quantity');
    }

    public function scopeSku($query, string $sku)
    {
        return $query->where('sku', $sku);
    }
}
