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
 * A transfer is in transit until the receiving branch confirms it.
 *
 * Per variant: source + target + in transit + short is constant at every step.
 */
class StockTransferTest extends TestCase
{
    use RefreshDatabase;

    private function send(User $user, $from, $to, array $items)
    {
        return $this->actingAs($user)->postJson('/api/stock/transfers', [
            'from_branch_id' => $from->id,
            'to_branch_id' => $to->id,
            'items' => $items,
        ]);
    }

    private function receive(User $user, string $transferId, array $items)
    {
        return $this->actingAs($user)->postJson("/api/stock/transfers/{$transferId}/receive", ['items' => $items]);
    }

    /** @return array{main: int, second: int, transit: int, short: int, total: int} */
    private function figures(ProductVariant $variant, Branch $main, Branch $second): array
    {
        $ledger = app(StockLedger::class);
        $lines = \App\Models\StockTransferItem::where('variant_id', $variant->id)
            ->whereHas('transfer', fn ($q) => $q->where('status', TransferStatus::InTransit))
            ->get();
        $received = \App\Models\StockTransferItem::where('variant_id', $variant->id)
            ->whereHas('transfer', fn ($q) => $q->where('status', TransferStatus::Received))
            ->get();

        $figures = [
            'main' => $ledger->balance($variant->id, $main->id),
            'second' => $ledger->balance($variant->id, $second->id),
            'transit' => (int) $lines->sum('quantity_sent'),
            // The shortage still open; settling moves it into a branch (see StockTransferSettlementTest).
            'short' => (int) $received->sum(fn ($line) => $line->quantityOpenShort()),
        ];
        $figures['total'] = array_sum($figures);

        return $figures;
    }

    private function setup3(): array
    {
        $this->tenant();
        [$main] = $this->shopFloor();
        [$second] = $this->shopFloor();
        $owner = User::factory()->owner()->create();
        $variant = ProductVariant::factory()->create();
        $this->giveStock($variant, $main, 10);
        $this->giveStock($variant, $second, 3);

        return [$main, $second, $owner, $variant];
    }

    public function test_sending_takes_stock_from_the_source_and_does_not_add_it_to_the_target(): void
    {
        [$main, $second, $owner, $variant] = $this->setup3();
        $before = $this->figures($variant, $main, $second);

        $this->send($owner, $main, $second, [['variant_id' => $variant->id, 'quantity' => 4]])
            ->assertCreated()
            ->assertJsonPath('data.status', 'in_transit')
            ->assertJsonPath('data.items.0.quantity_sent', 4)
            ->assertJsonPath('data.items.0.quantity_received', null);

        $after = $this->figures($variant, $main, $second);

        $this->assertSame(6, $after['main']);
        $this->assertSame(3, $after['second'], 'the target must not grow before it confirms');
        $this->assertSame(4, $after['transit']);
        $this->assertSame($before['total'], $after['total']);

        // The source's movement only: nothing was written at the target.
        $this->assertSame(1, StockMovement::where('type', StockMovementType::TransferOut)->count());
        $this->assertSame(0, StockMovement::where('type', StockMovementType::TransferIn)->count());
    }

    public function test_confirming_moves_it_into_the_target_and_nothing_is_left_in_transit(): void
    {
        [$main, $second, $owner, $variant] = $this->setup3();
        $before = $this->figures($variant, $main, $second);

        $id = $this->send($owner, $main, $second, [['variant_id' => $variant->id, 'quantity' => 4]])->json('data.id');

        $this->receive($owner, $id, [['variant_id' => $variant->id, 'quantity' => 4]])
            ->assertOk()
            ->assertJsonPath('data.status', 'received')
            ->assertJsonPath('data.has_shortage', false);

        $after = $this->figures($variant, $main, $second);

        $this->assertSame(6, $after['main']);
        $this->assertSame(7, $after['second']);
        $this->assertSame(0, $after['transit']);
        $this->assertSame(0, $after['short']);
        $this->assertSame($before['total'], $after['total']);
    }

    public function test_the_two_movements_share_the_transfers_id_and_the_movements_screen_shows_the_other_branch(): void
    {
        [$main, $second, $owner, $variant] = $this->setup3();

        $id = $this->send($owner, $main, $second, [['variant_id' => $variant->id, 'quantity' => 4]])->json('data.id');
        $this->receive($owner, $id, [['variant_id' => $variant->id, 'quantity' => 4]])->assertOk();

        $out = StockMovement::where('type', StockMovementType::TransferOut)->firstOrFail();
        $in = StockMovement::where('type', StockMovementType::TransferIn)->firstOrFail();

        $this->assertSame($id, $out->reference_id);
        $this->assertSame($id, $in->reference_id);
        $this->assertSame(-4, $out->quantity);
        $this->assertSame(4, $in->quantity);

        $this->actingAs($owner)
            ->getJson('/api/stock/movements?type=transfer_out&variant_id='.$variant->id)
            ->assertJsonPath('data.0.transfer_id', $id)
            ->assertJsonPath('data.0.counterpart_branch_id', $second->id);

        $this->actingAs($owner)
            ->getJson('/api/stock/movements?type=transfer_in&variant_id='.$variant->id)
            ->assertJsonPath('data.0.counterpart_branch_id', $main->id);
    }

    public function test_a_short_receipt_is_recorded_and_shown_to_the_owner(): void
    {
        [$main, $second, $owner, $variant] = $this->setup3();
        $before = $this->figures($variant, $main, $second);

        $id = $this->send($owner, $main, $second, [['variant_id' => $variant->id, 'quantity' => 3]])->json('data.id');

        $this->receive($owner, $id, [['variant_id' => $variant->id, 'quantity' => 2]])
            ->assertOk()
            ->assertJsonPath('data.status', 'received')
            ->assertJsonPath('data.has_shortage', true)
            ->assertJsonPath('data.items.0.quantity_sent', 3)
            ->assertJsonPath('data.items.0.quantity_received', 2)
            ->assertJsonPath('data.items.0.quantity_short', 1)
            ->assertJsonPath('data.pieces_short', 1);

        $after = $this->figures($variant, $main, $second);

        $this->assertSame(7, $after['main']);
        $this->assertSame(5, $after['second'], 'the target gets what arrived, not what was sent');
        $this->assertSame(0, $after['transit']);
        $this->assertSame(1, $after['short'], 'the missing piece is recorded, not dropped');
        $this->assertSame($before['total'], $after['total']);

        // The owner finds it: which transfer, between which branches, when.
        $this->actingAs($owner)->getJson('/api/stock/transfers?shortage=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $id)
            ->assertJsonPath('data.0.from_branch_id', $main->id)
            ->assertJsonPath('data.0.to_branch_id', $second->id)
            ->assertJsonPath('data.0.items.0.quantity_short', 1);

        $this->assertNotNull(StockTransfer::find($id)->received_at);
    }

    public function test_receiving_nothing_is_a_full_shortage_not_an_error(): void
    {
        [$main, $second, $owner, $variant] = $this->setup3();

        $id = $this->send($owner, $main, $second, [['variant_id' => $variant->id, 'quantity' => 3]])->json('data.id');

        $this->receive($owner, $id, [['variant_id' => $variant->id, 'quantity' => 0]])
            ->assertOk()->assertJsonPath('data.items.0.quantity_short', 3);

        $this->assertSame(3, app(StockLedger::class)->balance($variant->id, $second->id));
        $this->assertSame(0, StockMovement::where('type', StockMovementType::TransferIn)->count());
    }

    public function test_receiving_more_than_was_sent_is_refused_and_writes_nothing(): void
    {
        [$main, $second, $owner, $variant] = $this->setup3();
        $id = $this->send($owner, $main, $second, [['variant_id' => $variant->id, 'quantity' => 3]])->json('data.id');

        $movements = StockMovement::count();

        $this->receive($owner, $id, [['variant_id' => $variant->id, 'quantity' => 4]])
            ->assertStatus(422)->assertJsonPath('code', 'transfer_received_over_sent');

        $this->assertSame($movements, StockMovement::count());
        $this->assertSame(TransferStatus::InTransit, StockTransfer::find($id)->status);
    }

    public function test_a_receipt_must_name_every_line_once(): void
    {
        [$main, $second, $owner, $variant] = $this->setup3();
        $other = ProductVariant::factory()->create();
        $this->giveStock($other, $main, 5);

        $id = $this->send($owner, $main, $second, [
            ['variant_id' => $variant->id, 'quantity' => 2],
            ['variant_id' => $other->id, 'quantity' => 2],
        ])->json('data.id');

        $this->receive($owner, $id, [['variant_id' => $variant->id, 'quantity' => 2]])
            ->assertStatus(422)->assertJsonPath('code', 'transfer_receipt_lines_mismatch');

        $this->receive($owner, $id, [
            ['variant_id' => $variant->id, 'quantity' => 2],
            ['variant_id' => $variant->id, 'quantity' => 2],
        ])->assertStatus(422)->assertJsonPath('code', 'transfer_receipt_lines_mismatch');

        $this->assertSame(TransferStatus::InTransit, StockTransfer::find($id)->status);
    }

    public function test_the_target_manager_confirms_and_the_source_manager_cannot(): void
    {
        $this->tenant();
        [$main] = $this->shopFloor();
        [$second] = $this->shopFloor();
        $sourceManager = $this->manager($main);
        $targetManager = $this->manager($second);
        $variant = ProductVariant::factory()->create();
        $this->giveStock($variant, $main, 10);

        $id = $this->send($sourceManager, $main, $second, [['variant_id' => $variant->id, 'quantity' => 2]])
            ->assertCreated()->json('data.id');

        // The sender confirming its own shipment would make the confirmation meaningless.
        $this->receive($sourceManager, $id, [['variant_id' => $variant->id, 'quantity' => 2]])
            ->assertForbidden()->assertJsonPath('code', 'transfer_receive_not_allowed');

        $this->assertSame(TransferStatus::InTransit, StockTransfer::find($id)->status);
        $this->assertSame(0, app(StockLedger::class)->balance($variant->id, $second->id));

        $this->receive($targetManager, $id, [['variant_id' => $variant->id, 'quantity' => 2]])->assertOk();
        $this->assertSame(2, app(StockLedger::class)->balance($variant->id, $second->id));
    }

    public function test_a_seller_and_a_third_branchs_manager_cannot_confirm(): void
    {
        $this->tenant();
        [$main] = $this->shopFloor();
        [$second, , $targetSeller] = $this->shopFloor();
        [$third] = $this->shopFloor();
        $thirdManager = $this->manager($third);
        $owner = User::factory()->owner()->create();
        $variant = ProductVariant::factory()->create();
        $this->giveStock($variant, $main, 10);

        $id = $this->send($owner, $main, $second, [['variant_id' => $variant->id, 'quantity' => 2]])->json('data.id');

        $this->receive($targetSeller, $id, [['variant_id' => $variant->id, 'quantity' => 2]])->assertForbidden();
        $this->receive($thirdManager, $id, [['variant_id' => $variant->id, 'quantity' => 2]])
            ->assertForbidden()->assertJsonPath('code', 'transfer_receive_not_allowed');
    }

    public function test_a_transfer_is_confirmed_once(): void
    {
        [$main, $second, $owner, $variant] = $this->setup3();
        $id = $this->send($owner, $main, $second, [['variant_id' => $variant->id, 'quantity' => 2]])->json('data.id');

        $this->receive($owner, $id, [['variant_id' => $variant->id, 'quantity' => 2]])->assertOk();
        $this->receive($owner, $id, [['variant_id' => $variant->id, 'quantity' => 2]])
            ->assertStatus(409)->assertJsonPath('code', 'transfer_not_in_transit');

        $this->assertSame(5, app(StockLedger::class)->balance($variant->id, $second->id));
    }

    public function test_cancelling_before_receipt_puts_the_pieces_back_on_the_source(): void
    {
        [$main, $second, $owner, $variant] = $this->setup3();
        $before = $this->figures($variant, $main, $second);

        $sender = $this->manager($main);
        $id = $this->send($sender, $main, $second, [['variant_id' => $variant->id, 'quantity' => 4]])->json('data.id');

        $this->actingAs($sender)->postJson("/api/stock/transfers/{$id}/cancel")
            ->assertOk()->assertJsonPath('data.status', 'cancelled');

        $after = $this->figures($variant, $main, $second);

        $this->assertSame(10, $after['main']);
        $this->assertSame(3, $after['second']);
        $this->assertSame(0, $after['transit']);
        $this->assertSame($before['total'], $after['total']);
        $this->assertSame(1, StockMovement::where('type', StockMovementType::TransferCancel)->count());

        // And it cannot then be confirmed.
        $this->receive($owner, $id, [['variant_id' => $variant->id, 'quantity' => 4]])
            ->assertStatus(409)->assertJsonPath('code', 'transfer_not_in_transit');
    }

    public function test_it_cannot_be_cancelled_after_it_was_confirmed_or_by_the_target(): void
    {
        $this->tenant();
        [$main] = $this->shopFloor();
        [$second] = $this->shopFloor();
        $sourceManager = $this->manager($main);
        $targetManager = $this->manager($second);
        $variant = ProductVariant::factory()->create();
        $this->giveStock($variant, $main, 10);

        $id = $this->send($sourceManager, $main, $second, [['variant_id' => $variant->id, 'quantity' => 2]])->json('data.id');

        $this->actingAs($targetManager)->postJson("/api/stock/transfers/{$id}/cancel")
            ->assertForbidden()->assertJsonPath('code', 'transfer_cancel_not_allowed');

        $this->receive($targetManager, $id, [['variant_id' => $variant->id, 'quantity' => 2]])->assertOk();

        $this->actingAs($sourceManager)->postJson("/api/stock/transfers/{$id}/cancel")
            ->assertStatus(409)->assertJsonPath('code', 'transfer_not_in_transit');

        $this->assertSame(8, app(StockLedger::class)->balance($variant->id, $main->id));
    }

    public function test_the_target_sees_what_awaits_its_confirmation_first(): void
    {
        $this->tenant();
        [$main] = $this->shopFloor();
        [$second] = $this->shopFloor();
        $sourceManager = $this->manager($main);
        $targetManager = $this->manager($second);
        $variant = ProductVariant::factory()->create();
        $this->giveStock($variant, $main, 20);

        $done = $this->send($sourceManager, $main, $second, [['variant_id' => $variant->id, 'quantity' => 1]])->json('data.id');
        $this->receive($targetManager, $done, [['variant_id' => $variant->id, 'quantity' => 1]])->assertOk();
        $waiting = $this->send($sourceManager, $main, $second, [['variant_id' => $variant->id, 'quantity' => 2]])->json('data.id');

        $this->actingAs($targetManager)->getJson('/api/stock/transfers?box=incoming')
            ->assertOk()
            ->assertJsonPath('meta.awaiting', 1)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $waiting)
            ->assertJsonPath('data.0.status', 'in_transit');

        // The sender's list is what it sent and where each one stands; nothing awaits it.
        $this->actingAs($sourceManager)->getJson('/api/stock/transfers?box=outgoing')
            ->assertJsonCount(2, 'data')->assertJsonPath('meta.awaiting', 0);
    }

    public function test_a_manager_of_a_third_branch_does_not_see_the_transfer(): void
    {
        $this->tenant();
        [$main] = $this->shopFloor();
        [$second] = $this->shopFloor();
        [$third] = $this->shopFloor();
        $owner = User::factory()->owner()->create();
        $variant = ProductVariant::factory()->create();
        $this->giveStock($variant, $main, 10);

        $id = $this->send($owner, $main, $second, [['variant_id' => $variant->id, 'quantity' => 1]])->json('data.id');
        $outsider = $this->manager($third);

        $this->actingAs($outsider)->getJson('/api/stock/transfers')->assertJsonCount(0, 'data');
        $this->actingAs($outsider)->getJson("/api/stock/transfers/{$id}")->assertForbidden();
    }

    public function test_a_branch_with_a_transfer_cannot_be_deleted(): void
    {
        [$main, $second, $owner, $variant] = $this->setup3();
        $this->send($owner, $main, $second, [['variant_id' => $variant->id, 'quantity' => 1]])->assertCreated();

        // The target holds nothing from it yet, so only the transfer itself stops this.
        $fresh = Branch::factory()->create();
        $id = $this->send($owner, $main, $fresh, [['variant_id' => $variant->id, 'quantity' => 1]])->json('data.id');

        $this->actingAs($owner)->deleteJson("/api/branches/{$fresh->id}")
            ->assertStatus(422)->assertJsonPath('code', 'branch_has_transfers');

        $this->assertNotNull(StockTransfer::find($id));
    }

    public function test_more_than_the_balance_is_refused_and_writes_nothing(): void
    {
        $this->tenant();
        [$main] = $this->shopFloor();
        [$second] = $this->shopFloor();
        $owner = User::factory()->owner()->create();
        $enough = ProductVariant::factory()->create();
        $short = ProductVariant::factory()->create();
        $this->giveStock($enough, $main, 10);
        $this->giveStock($short, $main, 2);

        $before = StockMovement::count();

        $this->send($owner, $main, $second, [
            ['variant_id' => $enough->id, 'quantity' => 5],
            ['variant_id' => $short->id, 'quantity' => 3],
        ])->assertStatus(422)->assertJsonPath('code', 'out_of_stock');

        // All or nothing: the first line did not go through, and no transfer was left behind.
        $this->assertSame($before, StockMovement::count());
        $this->assertSame(0, StockTransfer::count());
    }

    public function test_the_same_branch_is_refused(): void
    {
        $this->tenant();
        [$main] = $this->shopFloor();
        $owner = User::factory()->owner()->create();
        $variant = ProductVariant::factory()->create();
        $this->giveStock($variant, $main, 10);

        $this->send($owner, $main, $main, [['variant_id' => $variant->id, 'quantity' => 1]])
            ->assertStatus(422)->assertJsonPath('code', 'transfer_same_branch');

        $this->assertSame(1, StockMovement::count());
    }

    public function test_zero_and_negative_quantities_are_refused(): void
    {
        [$main, $second, $owner, $variant] = $this->setup3();

        foreach ([0, -3] as $quantity) {
            $this->send($owner, $main, $second, [['variant_id' => $variant->id, 'quantity' => $quantity]])
                ->assertStatus(422);
        }

        $this->assertSame(10, app(StockLedger::class)->balance($variant->id, $main->id));
        $this->assertSame(0, StockTransfer::count());
    }

    public function test_a_manager_cannot_pull_stock_out_of_another_branch(): void
    {
        $this->tenant();
        [$main] = $this->shopFloor();
        [$second] = $this->shopFloor();
        $manager = $this->manager($main);
        $variant = ProductVariant::factory()->create();
        $this->giveStock($variant, $second, 10);

        $this->send($manager, $second, $main, [['variant_id' => $variant->id, 'quantity' => 2]])
            ->assertForbidden()->assertJsonPath('code', 'transfer_from_other_branch');

        $this->assertSame(10, app(StockLedger::class)->balance($variant->id, $second->id));
    }

    public function test_a_seller_cannot_transfer(): void
    {
        $this->tenant();
        [$main, , $seller] = $this->shopFloor();
        [$second] = $this->shopFloor();
        $variant = ProductVariant::factory()->create();
        $this->giveStock($variant, $main, 10);

        $this->send($seller, $main, $second, [['variant_id' => $variant->id, 'quantity' => 1]])
            ->assertForbidden();

        $this->assertSame(1, StockMovement::count());
    }

    public function test_another_shops_branch_is_not_a_target(): void
    {
        $this->tenant();
        [$main] = $this->shopFloor();
        $owner = User::factory()->owner()->create();
        $variant = ProductVariant::factory()->create();
        $this->giveStock($variant, $main, 10);

        $this->tenant();
        [$foreign] = $this->shopFloor();

        $this->send($owner, $main, $foreign, [['variant_id' => $variant->id, 'quantity' => 1]])
            ->assertStatus(422);
    }
}
