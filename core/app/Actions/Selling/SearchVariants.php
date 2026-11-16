<?php

namespace App\Actions\Selling;

use App\Models\ProductVariant;
use App\Services\StockLedger;
use Illuminate\Contracts\Pagination\Paginator;

/**
 * The way out when a label has fallen off: find the piece by what it is called.
 *
 * Every word typed must match the product name, the size or the colour, so
 * "قميص ازرق L" narrows instead of widening. Only active variants of active
 * products come back — the same ones a scan would accept. The business scope
 * and the soft-delete scopes apply on their own.
 */
class SearchVariants
{
    public const PER_PAGE = 20;

    public function __construct(protected StockLedger $stock) {}

    /** @return array{variants: Paginator, available: array<string, int>} */
    public function handle(string $query, string $branchId, int $page = 1): array
    {
        $words = preg_split('/\s+/u', trim($query), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $variants = ProductVariant::query()
            ->with('product')
            ->where('is_active', true)
            ->whereHas('product', fn ($q) => $q->where('is_active', true))
            ->where(function ($outer) use ($words) {
                foreach ($words as $word) {
                    $like = '%'.addcslashes($word, '\\%_').'%';

                    $outer->where(fn ($q) => $q
                        ->where('size', 'like', $like)
                        ->orWhere('color', 'like', $like)
                        ->orWhereHas('product', fn ($p) => $p->where('name', 'like', $like)));
                }
            })
            ->orderBy('product_id')
            ->orderBy('size')
            ->orderBy('id')
            // One page at a time, without a COUNT over the whole match.
            ->simplePaginate(self::PER_PAGE, ['*'], 'page', $page);

        // One query for the whole page, not one sum per row.
        $available = $this->stock
            ->balances($variants->pluck('id')->all(), $branchId)
            ->all();

        return ['variants' => $variants, 'available' => $available];
    }
}
