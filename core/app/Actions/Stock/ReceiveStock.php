<?php

namespace App\Actions\Stock;

use App\Enums\StockMovementType;
use App\Exceptions\DomainException;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\StockLedger;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Goods in: a delivery arrives and goes on the shelf. */
class ReceiveStock
{
    public function __construct(protected StockLedger $stock) {}

    /**
     * @param  array<int, array{variant_id: string, quantity: int}>  $items
     * @return Collection<int, \App\Models\StockMovement>
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
            $quantities = [];

            foreach ($items as $item) {
                $id = $item['variant_id'];
                $quantities[$id] = ($quantities[$id] ?? 0) + (int) $item['quantity'];
            }

            $variants = ProductVariant::whereIn('id', array_keys($quantities))->get()->keyBy('id');

            $movements = collect();

            foreach ($quantities as $variantId => $quantity) {
                if (! $variants->has($variantId)) {
                    throw DomainException::variantNotFound($variantId);
                }

                $movements->push($this->stock->post(
                    variant: $variantId,
                    branchId: $branchId,
                    quantity: $quantity,
                    type: StockMovementType::Purchase,
                    user: $user,
                    note: $note,
                ));
            }

            return $movements;
        });
    }
}
