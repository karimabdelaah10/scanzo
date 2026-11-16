<?php

namespace Tests\Feature;

use App\Enums\StockMovementType;
use App\Enums\TransferStatus;
use App\Models\Branch;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Models\User;
use App\Services\StockLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Transfers made under the old model (a pair of movements, the target credited
 * at once) become `received` transfers when the migration runs, with no
 * movement rewritten.
 */
class StockTransferMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_old_style_transfer_becomes_a_received_one_and_no_movement_changes(): void
    {
        $this->tenant();
        $main = Branch::factory()->create();
        $second = Branch::factory()->create();
        $owner = User::factory()->owner()->create();
        $variant = ProductVariant::factory()->create();
        $ledger = app(StockLedger::class);

        // Written exactly as the old TransferStock wrote it.
        $this->giveStock($variant, $main, 10);
        $ref = (string) \Illuminate\Support\Str::orderedUuid();
        foreach ([[$main, -4, StockMovementType::TransferOut], [$second, 4, StockMovementType::TransferIn]] as [$branch, $qty, $type]) {
            $ledger->post(
                variant: $variant, branchId: $branch->id, quantity: $qty, type: $type, user: $owner,
                note: 'old', referenceType: 'stock_transfer', referenceId: $ref,
            );
        }

        $movementsBefore = StockMovement::query()->orderBy('id')->get(['id', 'branch_id', 'quantity', 'type', 'reference_id'])->toArray();
        $this->assertSame(0, StockTransfer::count());

        $migration = require database_path('migrations/2026_11_16_000600_create_stock_transfers_tables.php');
        (fn () => $this->convertExistingTransfers())->call($migration);

        $transfer = StockTransfer::with('items')->findOrFail($ref);
        $this->assertSame(TransferStatus::Received, $transfer->status);
        $this->assertSame($main->id, $transfer->from_branch_id);
        $this->assertSame($second->id, $transfer->to_branch_id);
        $this->assertSame($owner->id, $transfer->sent_by);
        $this->assertSame($owner->id, $transfer->received_by);
        $this->assertNotNull($transfer->received_at);
        $this->assertFalse($transfer->has_shortage);
        $this->assertCount(1, $transfer->items);
        $this->assertSame(4, $transfer->items[0]->quantity_sent);
        $this->assertSame(4, $transfer->items[0]->quantity_received);

        $movementsAfter = StockMovement::query()->orderBy('id')->get(['id', 'branch_id', 'quantity', 'type', 'reference_id'])->toArray();
        $this->assertEquals($movementsBefore, $movementsAfter);
        $this->assertSame(4, $ledger->balance($variant->id, $second->id));
    }
}
