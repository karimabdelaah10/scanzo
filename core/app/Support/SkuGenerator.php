<?php

namespace App\Support;

use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;

/**
 * SKUs the shop never has to type: SKU-10001, SKU-10002, …
 *
 * The number is read back out of the column with CAST rather than by string
 * ordering, so the sequence keeps counting correctly past SKU-99999 instead of
 * silently going backwards when the digits grow.
 *
 * Call inside a transaction: it relies on the row lock, and the unique index on
 * (business_id, sku) makes a lost race fail loudly rather than duplicate.
 */
class SkuGenerator
{
    public const PREFIX = 'SKU-';

    protected const FIRST = 10001;

    public static function next(): string
    {
        $highest = ProductVariant::query()
            ->withTrashed()
            ->where('sku', 'like', self::PREFIX.'%')
            ->lockForUpdate()
            ->value(DB::raw(
                'MAX(CAST(SUBSTRING(sku, '.(strlen(self::PREFIX) + 1).') AS UNSIGNED))'
            ));

        $next = $highest === null ? self::FIRST : ((int) $highest) + 1;

        return self::PREFIX.$next;
    }

    /**
     * A run of codes for one product's variants, without a query per variant.
     *
     * @return array<int, string>
     */
    public static function take(int $count): array
    {
        $first = (int) substr(self::next(), strlen(self::PREFIX));

        return array_map(
            fn (int $offset) => self::PREFIX.($first + $offset),
            range(0, max(0, $count - 1)),
        );
    }
}
