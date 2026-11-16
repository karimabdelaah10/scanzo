<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use App\Support\Money;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A snapshot of what was sold. product_name, sku and unit_price are copies
 * taken at sale time and are never read back off the catalogue, so changing a
 * price or deleting a variant cannot rewrite an old receipt.
 */
class InvoiceItem extends Model
{
    use BelongsToBusiness, HasFactory, HasUuids;

    protected $fillable = [
        'business_id', 'invoice_id', 'variant_id',
        'product_name', 'variant_label', 'sku',
        'unit_price', 'discount_amount', 'invoice_discount_share', 'quantity', 'line_total', 'returned_quantity',
    ];

    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:2',
            // What the line was reduced by, in money. Never a percentage.
            'discount_amount' => 'decimal:2',
            // This line's share of the invoice discount, fixed at sale time.
            'invoice_discount_share' => 'decimal:2',
            // After the line discount, before the invoice discount's share.
            'line_total' => 'decimal:2',
            'quantity' => 'integer',
            'returned_quantity' => 'integer',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    /**
     * What the customer actually paid for this whole line, in piastres:
     * `line_total` (after the line discount) minus this line's stored share of
     * the invoice discount. Both are snapshots taken at sale time.
     */
    public function paidMinor(): int
    {
        return Money::toMinor($this->line_total) - Money::toMinor($this->invoice_discount_share);
    }

    /**
     * What the customer gets back for returning `$quantity` more pieces of
     * this line, in piastres: their share of what was PAID (`paidMinor()`) —
     * not `unit_price`, and not `line_total` alone, either of which would hand
     * back a discount the customer never paid for.
     */
    public function refundMinorFor(int $quantity): int
    {
        $paid = $this->paidMinor();

        return Money::portion($paid, $this->returned_quantity + $quantity, $this->quantity)
            - Money::portion($paid, $this->returned_quantity, $this->quantity);
    }

    /** How much of this line can still be returned. */
    public function returnableQuantity(): int
    {
        return max(0, $this->quantity - $this->returned_quantity);
    }

    public function isFullyReturned(): bool
    {
        return $this->returnableQuantity() === 0;
    }
}
