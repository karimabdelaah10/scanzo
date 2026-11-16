<?php

namespace App\Actions\Labels;

use App\Exceptions\DomainException;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\StockLedger;
use Illuminate\Support\Collection;

/**
 * Works out exactly which labels to print and how many of each.
 *
 * The default is one label per piece on the shelf, because that is what
 * labelling a delivery actually means: you have nine shirts, you need nine
 * stickers. Asking the shop to count them again into a form would be asking
 * for the number the system already knows.
 */
class BuildLabelSheet
{
    /** A whole roll of one code is almost always a mistake, so it is capped. */
    public const MAX_LABELS = 2000;

    public function __construct(protected StockLedger $stock) {}

    /**
     * @param  array<int, string>  $variantIds
     * @return Collection<int, array{variant: ProductVariant, copies: int}>
     */
    public function handle(
        array $variantIds = [],
        ?string $productId = null,
        ?string $branchId = null,
        string $copies = 'stock',
        ?int $fixedCopies = null,
    ): Collection {
        $query = ProductVariant::query()->with('product');

        if ($productId !== null) {
            // Every size and colour of one product, which is how a new
            // delivery of a model gets labelled.
            Product::findOrFail($productId);
            $query->where('product_id', $productId);
        } elseif ($variantIds !== []) {
            $query->whereIn('id', $variantIds);
        } else {
            throw DomainException::nothingToPrint();
        }

        $variants = $query->orderBy('sku')->get();

        if ($variants->isEmpty()) {
            throw DomainException::nothingToPrint();
        }

        $balances = $copies === 'stock' && $branchId !== null
            ? $this->stock->balances($variants->pluck('id')->all(), $branchId)
            : collect();

        $rows = $variants
            ->map(function (ProductVariant $variant) use ($copies, $fixedCopies, $balances) {
                $count = match ($copies) {
                    // Never negative: a shelf that went below zero still gets
                    // no labels rather than a nonsensical count.
                    'stock' => max(0, (int) ($balances[$variant->id] ?? 0)),
                    'fixed' => max(0, $fixedCopies ?? 1),
                    default => 1,
                };

                return ['variant' => $variant, 'copies' => $count];
            })
            ->filter(fn (array $row) => $row['copies'] > 0)
            ->values();

        if ($rows->isEmpty()) {
            throw DomainException::nothingToPrint();
        }

        $total = $rows->sum('copies');

        if ($total > self::MAX_LABELS) {
            throw DomainException::tooManyLabels($total, self::MAX_LABELS);
        }

        return $rows;
    }
}
