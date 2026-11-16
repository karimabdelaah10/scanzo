<?php

namespace App\Models;

use App\Enums\ItemCondition;
use App\Models\Concerns\BelongsToBusiness;
use App\Support\Money;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaleReturnItem extends Model
{
    use BelongsToBusiness, HasFactory, HasUuids;

    protected $fillable = [
        'business_id', 'sale_return_id', 'invoice_item_id', 'variant_id',
        'quantity', 'unit_price', 'amount', 'restock', 'condition',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            // The price it was sold at, not today's.
            'unit_price' => 'decimal:2',
            // What was actually handed back: the paid share, not unit_price * quantity.
            'amount' => 'decimal:2',
            'restock' => 'boolean',
            'condition' => ItemCondition::class,
        ];
    }

    public function saleReturn(): BelongsTo
    {
        return $this->belongsTo(SaleReturn::class);
    }

    public function invoiceItem(): BelongsTo
    {
        return $this->belongsTo(InvoiceItem::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    public function lineTotal(): string
    {
        return Money::toDecimal(Money::toMinor($this->amount));
    }
}
