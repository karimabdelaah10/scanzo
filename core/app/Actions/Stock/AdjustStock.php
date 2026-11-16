<?php

namespace App\Actions\Stock;

use App\Enums\StockMovementType;
use App\Exceptions\DomainException;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\StockLedger;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * A correction after counting the shelf.
 *
 * The caller sends what it counted, not a delta, because that is what a person
 * holding the pieces actually knows. The difference against the ledger is what
 * gets posted — so a count that matches writes nothing at all, and the history
 * says who corrected what and when.
 */
class AdjustStock
{
    public function __construct(protected StockLedger $stock) {}

    /**
     * @param  array<int, array{variant_id: string, counted: int}>  $items
     * @return Collection<int, array{variant_id: string, was: int, counted: int, difference: int}>
     */
    public function handle(User $user, string $branchId, array $items, ?string $note = null): Collection
    {
        if ($items === []) {
            throw DomainException::emptyCart();
        }

        if (! $user->canAccessBranch($branchId)) {
            throw DomainException::branchNotAllowed();
        }

        return DB::transaction(function () use ($user, $branchId, $items, $note) {
            $counted = [];

            foreach ($items as $item) {
                // A later line for the same variant wins: it is the newer count.
                $counted[$item['variant_id']] = (int) $item['counted'];
            }

            $variantIds = array_keys($counted);
            $variants = ProductVariant::whereIn('id', $variantIds)->get()->keyBy('id');
            $balances = $this->stock->balances($variantIds, $branchId);

            $result = collect();

            foreach ($counted as $variantId => $count) {
                if (! $variants->has($variantId)) {
                    throw DomainException::variantNotFound($variantId);
                }

                $was = (int) ($balances[$variantId] ?? 0);
                $difference = $count - $was;

                if ($difference !== 0) {
                    $this->stock->post(
                        variant: $variantId,
                        branchId: $branchId,
                        quantity: $difference,
                        type: StockMovementType::Adjustment,
                        user: $user,
                        note: $note ?? 'جرد',
                    );
                }

                $result->push([
                    'variant_id' => $variantId,
                    'was' => $was,
                    'counted' => $count,
                    'difference' => $difference,
                ]);
            }

            return $result;
        });
    }
}
