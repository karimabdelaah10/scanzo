<?php

namespace App\Services;

use App\Enums\StockMovementType;
use App\Models\Device;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The only way stock is read or written.
 *
 * A balance is the sum of the ledger, never a stored column, so two devices
 * that sold the same last piece offline both end up with a valid movement and
 * the balance simply goes negative — visible, instead of a lost sale.
 */
class StockLedger
{
    public function balance(string $variantId, string $branchId): int
    {
        return (int) StockMovement::query()
            ->where('variant_id', $variantId)
            ->where('branch_id', $branchId)
            ->sum('quantity');
    }

    /**
     * Balances for several variants in one query — what the cart needs so it
     * does not run one sum per line.
     *
     * @param  array<int, string>  $variantIds
     * @return Collection<string, int> keyed by variant id
     */
    public function balances(array $variantIds, string $branchId): Collection
    {
        if ($variantIds === []) {
            return collect();
        }

        return StockMovement::query()
            ->select('variant_id', DB::raw('SUM(quantity) as balance'))
            ->whereIn('variant_id', $variantIds)
            ->where('branch_id', $branchId)
            ->groupBy('variant_id')
            ->pluck('balance', 'variant_id')
            ->map(fn ($balance) => (int) $balance);
    }

    /** Balances of every variant in a branch that has gone to or below zero. */
    public function negativeBalances(string $branchId): Collection
    {
        return StockMovement::query()
            ->select('variant_id', DB::raw('SUM(quantity) as balance'))
            ->where('branch_id', $branchId)
            ->groupBy('variant_id')
            ->havingRaw('SUM(quantity) < 0')
            ->pluck('balance', 'variant_id')
            ->map(fn ($balance) => (int) $balance);
    }

    /**
     * Append one movement. `quantity` is signed by the caller: negative takes
     * stock out. Movements are never updated or deleted — post a correcting
     * one instead.
     */
    public function post(
        ProductVariant|string $variant,
        string $branchId,
        int $quantity,
        StockMovementType $type,
        ?Model $reference = null,
        ?User $user = null,
        Device|string|null $device = null,
        ?string $note = null,
        ?\DateTimeInterface $occurredAt = null,
        ?string $referenceType = null,
        ?string $referenceId = null,
    ): StockMovement {
        return StockMovement::create([
            'branch_id' => $branchId,
            'variant_id' => $variant instanceof ProductVariant ? $variant->id : $variant,
            'quantity' => $quantity,
            'type' => $type,
            // A model, or a bare type and id for a reference with no table of
            // its own (a stock transfer is only the pair of movements).
            'reference_type' => $reference?->getMorphClass() ?? $referenceType,
            'reference_id' => $reference?->getKey() ?? $referenceId,
            'user_id' => $user?->id,
            'device_id' => $device instanceof Device ? $device->id : $device,
            'note' => $note,
            'occurred_at' => $occurredAt ?? now(),
        ]);
    }
}
