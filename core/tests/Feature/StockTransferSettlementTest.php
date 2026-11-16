<?php

namespace Tests\Feature;

use App\Enums\StockMovementType;
use App\Enums\TransferStatus;
use App\Models\Branch;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use App\Models\StockTransferSettlement;
use App\Models\User;
use App\Services\StockLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A shortage found later is SETTLED: it moves into a branch, it does not vanish.
 *
 * source + target + in transit + OPEN shortage = constant, before and after.
 * Also: the late-transfer mark and the in-transit column on the balances.
 */
class StockTransferSettlementTest extends TestCase
{
    use RefreshDatabase;

    private function send(User $user, $from, $to, array $items)
    {
        return $this->actingAs($user)->postJson('/api/stock/transfers', [
            'from_branch_id' => $from->id, 'to_branch_id' => $to->id, 'items' => $items,
        ]);
    }

    private function receive(User $user, string $id, array $items)
    {
        return $this->actingAs($user)->postJson("/api/stock/transfers/{$id}/receive", ['items' => $items]);
    }

    private function settle(User $user, string $id, $branch, array $items, ?string $reason = 'found in the van')
    {
        return $this->actingAs($user)->postJson("/api/stock/transfers/{$id}/settle", array_filter([
            'branch_id' => $branch->id, 'reason' => $reason, 'items' => $items,
        ], fn ($v) => $v !== null));
    }

    /** @return array{main: int, second: int, transit: int, short: int, total: int} */
    private function figures(ProductVariant $variant, Branch $main, Branch $second): array
    {
        $ledger = app(StockLedger::class);
        $lines = fn (TransferStatus $s) => StockTransferItem::where('variant_id', $variant->id)
            ->whereHas('transfer', fn ($q) => $q->where('status', $s))->get();

        $figures = [
            'main' => $ledger->balance($variant->id, $main->id),
            'second' => $ledger->balance($variant->id, $second->id),
            'transit' => (int) $lines(TransferStatus::InTransit)->sum('quantity_sent'),
            'short' => (int) $lines(TransferStatus::Received)->sum(fn ($l) => $l->quantityOpenShort()),
        ];
        $figures['total'] = array_sum($figures);

        return $figures;
    }

    /** A transfer of 5 from main to second, of which 2 arrived: 3 short. */
    private function shortTransfer(): array
    {
        $this->tenant();
        [$main] = $this->shopFloor();
        [$second] = $this->shopFloor();
        $owner = User::factory()->owner()->create();
        $variant = ProductVariant::factory()->create();
        $this->giveStock($variant, $main, 10);

        $id = $this->send($owner, $main, $second, [['variant_id' => $variant->id, 'quantity' => 5]])->json('data.id');
        $this->receive($owner, $id, [['variant_id' => $variant->id, 'quantity' => 2]])->assertOk();

        return [$main, $second, $owner, $variant, $id];
    }

    public function test_settling_at_the_target_moves_the_shortage_into_that_branch_and_the_sum_holds(): void
    {
        [$main, $second, $owner, $variant, $id] = $this->shortTransfer();
        $before = $this->figures($variant, $main, $second);
        $this->assertSame(3, $before['short']);

        $this->settle($owner, $id, $second, [['variant_id' => $variant->id, 'quantity' => 3]], 'arrived the next day')
            ->assertOk()
            ->assertJsonPath('data.has_shortage', false)
            ->assertJsonPath('data.items.0.quantity_short', 3)
            ->assertJsonPath('data.items.0.quantity_settled', 3)
            ->assertJsonPath('data.items.0.quantity_open_short', 0)
            ->assertJsonPath('data.settlements.0.reason', 'arrived the next day')
            ->assertJsonPath('data.settlements.0.branch_id', $second->id);

        $after = $this->figures($variant, $main, $second);
        $this->assertSame(5, $after['second']);
        $this->assertSame(0, $after['short'], 'the open shortage moved into the branch');
        $this->assertSame($before['total'], $after['total']);

        // The movement is a normal ledger row on the transfer, by the settler, with the reason.
        $movement = StockMovement::where('type', StockMovementType::TransferSettlement)->sole();
        $this->assertSame(3, $movement->quantity);
        $this->assertSame($second->id, $movement->branch_id);
        $this->assertSame($id, $movement->reference_id);
        $this->assertSame($owner->id, $movement->user_id);
        $this->assertSame('arrived the next day', $movement->note);

        $row = StockTransferSettlement::sole();
        $this->assertSame($owner->id, $row->settled_by);
        $this->assertNotNull($row->settled_at);

        // Without subtracting what was settled the figure would lie: this is why the equation says OPEN.
        $naive = $after['main'] + $after['second'] + $after['transit']
            + StockTransferItem::where('variant_id', $variant->id)->get()->sum(fn ($l) => $l->quantityShort());
        $this->assertNotSame($before['total'], $naive);
    }

    public function test_settling_at_the_source_means_it_never_left(): void
    {
        [$main, $second, $owner, $variant, $id] = $this->shortTransfer();
        $before = $this->figures($variant, $main, $second);

        $this->settle($owner, $id, $main, [['variant_id' => $variant->id, 'quantity' => 3]], 'never loaded on the van')->assertOk();

        $after = $this->figures($variant, $main, $second);
        $this->assertSame($before['main'] + 3, $after['main']);
        $this->assertSame($before['second'], $after['second']);
        $this->assertSame(0, $after['short']);
        $this->assertSame($before['total'], $after['total']);
    }

    public function test_a_partial_settlement_leaves_the_rest_open_and_can_be_followed_by_another(): void
    {
        [$main, $second, $owner, $variant, $id] = $this->shortTransfer();
        $before = $this->figures($variant, $main, $second);

        $this->settle($owner, $id, $second, [['variant_id' => $variant->id, 'quantity' => 2]])
            ->assertOk()
            ->assertJsonPath('data.has_shortage', true)
            ->assertJsonPath('data.items.0.quantity_open_short', 1)
            ->assertJsonPath('data.pieces_short', 1)
            ->assertJsonPath('data.pieces_settled', 2);

        $mid = $this->figures($variant, $main, $second);
        $this->assertSame(1, $mid['short']);
        $this->assertSame($before['total'], $mid['total']);

        // Still on the owner's shortage list, with one piece left.
        $this->actingAs($owner)->getJson('/api/stock/transfers?shortage=1')->assertJsonCount(1, 'data');

        // The last piece went back to the source.
        $this->settle($owner, $id, $main, [['variant_id' => $variant->id, 'quantity' => 1]], 'counted on the shelf')
            ->assertOk()->assertJsonPath('data.has_shortage', false);

        $end = $this->figures($variant, $main, $second);
        $this->assertSame(0, $end['short']);
        $this->assertSame($before['total'], $end['total']);
        $this->actingAs($owner)->getJson('/api/stock/transfers?shortage=1')->assertJsonCount(0, 'data');
        $this->assertSame(2, StockTransferSettlement::count());
    }

    public function test_settling_more_than_is_still_short_is_refused_and_writes_nothing(): void
    {
        [$main, $second, $owner, $variant, $id] = $this->shortTransfer();
        $before = $this->figures($variant, $main, $second);
        $movements = StockMovement::count();

        $this->settle($owner, $id, $second, [['variant_id' => $variant->id, 'quantity' => 4]])
            ->assertStatus(422)->assertJsonPath('code', 'transfer_settle_over_short');

        // Two requests that each fit but together do not.
        $this->settle($owner, $id, $second, [['variant_id' => $variant->id, 'quantity' => 2]])->assertOk();
        $this->settle($owner, $id, $second, [['variant_id' => $variant->id, 'quantity' => 2]])
            ->assertStatus(422)->assertJsonPath('code', 'transfer_settle_over_short');

        // The same variant twice in one request is added up before the check.
        $this->settle($owner, $id, $second, [
            ['variant_id' => $variant->id, 'quantity' => 1], ['variant_id' => $variant->id, 'quantity' => 1],
        ])->assertStatus(422);

        $after = $this->figures($variant, $main, $second);
        $this->assertSame($movements + 1, StockMovement::count());
        $this->assertSame(1, $after['short']);
        $this->assertSame($before['total'], $after['total']);
    }

    public function test_a_reason_is_required(): void
    {
        [$main, $second, $owner, $variant, $id] = $this->shortTransfer();
        $line = [['variant_id' => $variant->id, 'quantity' => 1]];

        $this->settle($owner, $id, $second, $line, null)->assertStatus(422);
        // Spaces are trimmed to nothing by the framework before validation, so this is the plain `required` rule.
        $this->settle($owner, $id, $second, $line, '   ')->assertStatus(422)->assertJsonValidationErrors('reason');

        $this->assertSame(0, StockTransferSettlement::count());
        $this->assertSame(0, StockMovement::where('type', StockMovementType::TransferSettlement)->count());
    }

    public function test_only_the_owner_or_the_manager_of_the_branch_the_pieces_enter_may_settle(): void
    {
        [$main, $second, $owner, $variant, $id] = $this->shortTransfer();
        [$third] = $this->shopFloor();
        $line = [['variant_id' => $variant->id, 'quantity' => 1]];

        $this->settle($this->manager($third), $id, $second, $line)
            ->assertForbidden()->assertJsonPath('code', 'transfer_settle_not_allowed');
        $this->settle(User::factory()->seller($second)->create(), $id, $second, $line)->assertForbidden();

        // The target's manager settles into the target, not into the source.
        $targetManager = $this->manager($second);
        $this->settle($targetManager, $id, $main, $line)->assertForbidden();
        $this->settle($targetManager, $id, $second, $line)->assertOk();

        // The source's manager settles into the source.
        $this->settle($this->manager($main), $id, $main, $line)->assertOk();

        // A branch outside the transfer is refused even for the owner.
        $this->settle($owner, $id, $third, $line)
            ->assertStatus(422)->assertJsonPath('code', 'transfer_settle_branch_invalid');
    }

    public function test_only_a_received_transfer_with_an_open_shortage_can_be_settled(): void
    {
        $this->tenant();
        [$main] = $this->shopFloor();
        [$second] = $this->shopFloor();
        $owner = User::factory()->owner()->create();
        $variant = ProductVariant::factory()->create();
        $this->giveStock($variant, $main, 10);
        $line = [['variant_id' => $variant->id, 'quantity' => 1]];

        $transit = $this->send($owner, $main, $second, [['variant_id' => $variant->id, 'quantity' => 3]])->json('data.id');
        $this->settle($owner, $transit, $second, $line)->assertStatus(409)->assertJsonPath('code', 'transfer_settle_nothing_open');

        $this->receive($owner, $transit, [['variant_id' => $variant->id, 'quantity' => 3]]);
        $this->settle($owner, $transit, $second, $line)->assertStatus(409);

        $cancelled = $this->send($owner, $main, $second, [['variant_id' => $variant->id, 'quantity' => 1]])->json('data.id');
        $this->actingAs($owner)->postJson("/api/stock/transfers/{$cancelled}/cancel");
        $this->settle($owner, $cancelled, $main, $line)->assertStatus(409);
    }

    public function test_a_transfer_closed_months_ago_can_still_be_settled(): void
    {
        [$main, $second, $owner, $variant, $id] = $this->shortTransfer();
        $before = $this->figures($variant, $main, $second);

        $this->travel(5)->months();
        $this->settle($owner, $id, $second, [['variant_id' => $variant->id, 'quantity' => 1]], 'found in a stock count')->assertOk();

        $this->assertSame($before['total'], $this->figures($variant, $main, $second)['total']);
    }

    public function test_a_transfer_in_transit_past_the_limit_is_marked_late_and_the_limit_is_a_setting(): void
    {
        $this->tenant();
        [$main] = $this->shopFloor();
        [$second] = $this->shopFloor();
        $owner = User::factory()->owner()->create();
        $variant = ProductVariant::factory()->create();
        $this->giveStock($variant, $main, 10);

        $id = $this->send($owner, $main, $second, [['variant_id' => $variant->id, 'quantity' => 1]])->json('data.id');

        $this->actingAs($owner)->getJson('/api/stock/transfers')
            ->assertJsonPath('data.0.is_late', false)->assertJsonPath('data.0.late_after_days', 2)
            ->assertJsonPath('meta.late', 0);

        $this->travel(3)->days();
        $this->actingAs($owner)->getJson('/api/stock/transfers')
            ->assertJsonPath('data.0.is_late', true)->assertJsonPath('meta.late', 1);
        $this->actingAs($owner)->getJson('/api/stock/transfers?late=1')->assertJsonCount(1, 'data');

        // The shop widens the limit: no longer late.
        $this->actingAs($owner)->patchJson('/api/settings', ['transfers' => ['late_after_days' => 5]])
            ->assertOk()->assertJsonPath('data.transfers.late_after_days', 5);
        $this->actingAs($owner)->getJson('/api/stock/transfers?late=1')->assertJsonCount(0, 'data');
        $this->actingAs($owner)->patchJson('/api/settings', ['transfers' => ['late_after_days' => 0]])->assertStatus(422);

        // Once received it is not in transit, so it is never late.
        $this->actingAs($owner)->patchJson('/api/settings', ['transfers' => ['late_after_days' => 1]]);
        $this->receive($owner, $id, [['variant_id' => $variant->id, 'quantity' => 1]]);
        $this->actingAs($owner)->getJson('/api/stock/transfers')->assertJsonPath('data.0.is_late', false);
    }

    public function test_the_balances_show_what_is_on_its_way_in_a_separate_column_not_added_to_the_balance(): void
    {
        $this->tenant();
        [$main] = $this->shopFloor();
        [$second] = $this->shopFloor();
        $owner = User::factory()->owner()->create();
        $variant = ProductVariant::factory()->create();
        $this->giveStock($variant, $main, 10);
        $this->giveStock($variant, $second, 3);

        $id = $this->send($owner, $main, $second, [['variant_id' => $variant->id, 'quantity' => 4]])->json('data.id');

        $row = fn ($branch) => collect($this->actingAs($owner)->getJson("/api/stock?branch_id={$branch->id}")->json('data'))
            ->firstWhere('variant_id', $variant->id);

        $this->assertSame(3, $row($second)['quantity'], 'the target does not hold them yet');
        $this->assertSame(4, $row($second)['in_transit_quantity']);
        $this->assertSame(6, $row($main)['quantity']);
        $this->assertSame(0, $row($main)['in_transit_quantity'], 'in transit is shown on the branch it is going to');

        $this->receive($owner, $id, [['variant_id' => $variant->id, 'quantity' => 4]]);

        $this->assertSame(7, $row($second)['quantity']);
        $this->assertSame(0, $row($second)['in_transit_quantity']);
    }
}
