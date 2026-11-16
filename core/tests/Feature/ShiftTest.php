<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Device;
use App\Models\Invoice;
use App\Models\ProductVariant;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * A till shift answers one question: the difference between the cash that
 * should be in the drawer and the cash that is.
 */
class ShiftTest extends TestCase
{
    use RefreshDatabase;

    private const MORNING = '2026-11-16 09:00:00';

    /** @return array{0: Branch, 1: Device, 2: User, 3: User} branch, till, seller, manager */
    private function floor(): array
    {
        $this->tenant();
        [$branch, $device, $seller] = $this->shopFloor();

        return [$branch, $device, $seller, $this->manager($branch)];
    }

    private function open(User $user, Device $device, float $float = 200, array $extra = [])
    {
        return $this->actingAs($user)->postJson('/api/shifts', [
            'device_id' => $device->id,
            'opening_float' => $float,
            ...$extra,
        ]);
    }

    private function close(User $user, string $shiftId, float $counted, array $extra = [])
    {
        return $this->actingAs($user)->postJson("/api/shifts/{$shiftId}/close", [
            'counted_cash' => $counted,
            ...$extra,
        ]);
    }

    private function sale(User $user, Device $device, ProductVariant $variant, string $method = 'cash', int $qty = 1): Invoice
    {
        // By the id in the response: with the clock frozen every invoice has
        // the same created_at, so "the latest" is not a thing.
        $id = $this->actingAs($user)->postJson('/api/invoices', [
            'device_id' => $device->id,
            'items' => [['variant_id' => $variant->id, 'quantity' => $qty]],
            'payment_method' => $method,
        ])->assertCreated()->json('data.id');

        return Invoice::findOrFail($id);
    }

    private function stocked(Branch $branch, int $price): ProductVariant
    {
        $variant = ProductVariant::factory()->create(['price' => $price]);
        $this->giveStock($variant, $branch, 50);

        return $variant;
    }

    public function test_expected_is_the_float_plus_cash_sales_minus_cash_refunds_and_card_is_left_out(): void
    {
        [$branch, $device, $seller, $manager] = $this->floor();
        $shirt = $this->stocked($branch, 450);
        $hat = $this->stocked($branch, 150);
        $scarf = $this->stocked($branch, 300);

        $this->travelTo(self::MORNING);
        $shiftId = $this->open($seller, $device, 200)->assertCreated()->json('data.id');

        $this->sale($seller, $device, $shirt);                       // +450 cash
        $hatSale = $this->sale($seller, $device, $hat);              // +150 cash
        $this->sale($seller, $device, $scarf, 'card');               // 300 by card: not in the drawer
        $this->sale($seller, $device, $scarf, 'wallet');             // 300 by wallet: not in the drawer

        // The hat comes back and is refunded in cash: -150.
        $this->actingAs($manager)->postJson('/api/returns', [
            'device_id' => $device->id,
            'invoice_id' => $hatSale->id,
            'items' => [['invoice_item_id' => $hatSale->items()->firstOrFail()->id, 'quantity' => 1]],
            'refund_method' => 'cash',
        ])->assertCreated();

        // 200 + 450 + 150 - 150 = 650.
        $this->close($seller, $shiftId, 650)
            ->assertOk()
            ->assertJsonPath('data.expected_cash', '650.00')
            ->assertJsonPath('data.counted_cash', '650.00')
            ->assertJsonPath('data.difference', '0.00');
    }

    public function test_an_exchange_counts_what_the_customer_handed_over_not_the_new_invoice_in_full(): void
    {
        [$branch, $device, $seller, $manager] = $this->floor();
        $small = $this->stocked($branch, 450);
        $large = $this->stocked($branch, 650);

        $this->travelTo(self::MORNING);
        $shiftId = $this->open($seller, $device, 0)->assertCreated()->json('data.id');

        $original = $this->sale($seller, $device, $small); // +450

        // Swap the 450 shirt for a 650 one: the new invoice is 650 in full but
        // the customer only hands over 200 more.
        $this->actingAs($manager)->postJson('/api/returns', [
            'device_id' => $device->id,
            'invoice_id' => $original->id,
            'items' => [['invoice_item_id' => $original->items()->firstOrFail()->id, 'quantity' => 1]],
            'exchange_items' => [['variant_id' => $large->id, 'quantity' => 1]],
            'payment_method' => 'cash',
        ])->assertCreated();

        // 450 (first sale) + 200 (the difference) = 650. Counting the new
        // invoice in full would say 1100.
        $this->close($seller, $shiftId, 650)->assertJsonPath('data.expected_cash', '650.00');
    }

    public function test_a_cash_invoice_voided_in_the_same_shift_is_not_in_the_drawer(): void
    {
        [$branch, $device, $seller, $manager] = $this->floor();
        $shirt = $this->stocked($branch, 450);

        $this->travelTo(self::MORNING);
        $shiftId = $this->open($seller, $device, 100)->assertCreated()->json('data.id');

        $invoice = $this->sale($seller, $device, $shirt);
        $this->actingAs($manager)->postJson("/api/invoices/{$invoice->id}/void", ['reason' => 'خطأ'])->assertOk();

        $this->close($seller, $shiftId, 100)->assertJsonPath('data.expected_cash', '100.00');
    }

    public function test_a_shortage_and_a_surplus_are_stored_with_their_sign(): void
    {
        [$branch, $device, $seller] = $this->floor();
        $shirt = $this->stocked($branch, 450);

        $this->travelTo(self::MORNING);
        $first = $this->open($seller, $device, 200)->json('data.id');
        $this->sale($seller, $device, $shirt);

        // Expected 650, counted 620: 30 short.
        $this->close($seller, $first, 620, ['note' => 'فكّة اتدفعت لعميل'])
            ->assertJsonPath('data.difference', '-30.00')
            ->assertJsonPath('data.note', 'فكّة اتدفعت لعميل');

        $this->assertSame('-30.00', (string) Shift::findOrFail($first)->difference);

        // A second shift on the same till, 5 over.
        $this->travelTo('2026-11-16 15:00:00');
        $second = $this->open($seller, $device, 100)->json('data.id');
        $this->close($seller, $second, 105)->assertJsonPath('data.difference', '5.00');
    }

    public function test_another_tills_cash_is_not_in_this_drawer(): void
    {
        [$branch, $device, $seller] = $this->floor();
        $other = Device::factory()->create(['branch_id' => $branch->id]);
        $shirt = $this->stocked($branch, 450);

        $this->travelTo(self::MORNING);
        $shiftId = $this->open($seller, $device, 100)->json('data.id');
        $this->sale($seller, $other, $shirt);

        $this->close($seller, $shiftId, 100)->assertJsonPath('data.expected_cash', '100.00');
    }

    public function test_the_expected_cash_is_never_shown_while_the_shift_is_open(): void
    {
        [$branch, $device, $seller] = $this->floor();
        $shirt = $this->stocked($branch, 450);

        $this->travelTo(self::MORNING);
        $opened = $this->open($seller, $device, 200)->assertCreated();
        $this->sale($seller, $device, $shirt);

        $opened->assertJsonMissingPath('data.expected_cash')->assertJsonMissingPath('data.difference');

        $this->actingAs($seller)->getJson("/api/shifts/current?device_id={$device->id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'open')
            ->assertJsonMissingPath('data.expected_cash')
            ->assertJsonMissingPath('data.difference');

        // And a manager looking at the list does not get it either, until it is counted.
        $this->actingAs($this->manager($branch))->getJson('/api/shifts')
            ->assertOk()
            ->assertJsonMissingPath('data.0.expected_cash');
    }

    public function test_a_seller_cannot_close_a_colleagues_shift_but_a_manager_can(): void
    {
        [$branch, $device, $seller, $manager] = $this->floor();
        $colleague = User::factory()->seller($branch)->create();

        $this->travelTo(self::MORNING);
        $shiftId = $this->open($seller, $device, 100)->json('data.id');

        $this->close($colleague, $shiftId, 100)->assertForbidden()->assertJsonPath('code', 'shift_not_yours');
        $this->assertNull(Shift::findOrFail($shiftId)->closed_at);

        $this->close($manager, $shiftId, 100)
            ->assertOk()
            ->assertJsonPath('data.status', 'closed')
            ->assertJsonPath('data.closed_by', $manager->name)
            ->assertJsonPath('data.opened_by', $seller->name);
    }

    public function test_a_manager_of_another_branch_cannot_close_it(): void
    {
        [$branch, $device, $seller] = $this->floor();
        $stranger = $this->manager(Branch::factory()->create());

        $this->travelTo(self::MORNING);
        $shiftId = $this->open($seller, $device, 100)->json('data.id');

        $this->close($stranger, $shiftId, 100)->assertForbidden()->assertJsonPath('code', 'branch_not_allowed');
    }

    public function test_a_shift_left_open_from_yesterday_is_closed_without_a_count_when_the_next_one_opens(): void
    {
        [$branch, $device, $seller] = $this->floor();
        $shirt = $this->stocked($branch, 450);

        $this->travelTo('2026-11-15 09:00:00');
        $old = $this->open($seller, $device, 200)->json('data.id');

        $this->travelTo('2026-11-15 17:30:00');
        $this->sale($seller, $device, $shirt);

        // Nobody closed it. Next morning the till opens a new one.
        $this->travelTo(self::MORNING);
        $new = $this->open($seller, $device, 150)
            ->assertCreated()
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('auto_closed.id', $old)
            ->assertJsonPath('auto_closed.close_kind', 'auto')
            // 200 + 450, kept for the manager. No count, so no difference.
            ->assertJsonPath('auto_closed.expected_cash', '650.00')
            ->assertJsonPath('auto_closed.counted_cash', null)
            ->assertJsonPath('auto_closed.difference', null)
            // Dated at the till's last sale, not at the moment the next day began.
            ->assertJsonPath('auto_closed.closed_at', '2026-11-15T17:30:00+00:00')
            ->json('data.id');

        $oldRow = Shift::findOrFail($old);
        $this->assertNotNull($oldRow->closed_at);
        $this->assertNull($oldRow->closed_by);
        $this->assertNull($oldRow->open_device_id);
        $this->assertSame($new, Shift::whereNull('closed_at')->firstOrFail()->id);

        // The managers' list flags it as a shift that did not balance.
        $this->actingAs($this->manager($branch))->getJson('/api/shifts?problems=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $old);
    }

    public function test_the_till_reports_yesterdays_open_shift_as_stale(): void
    {
        [, $device, $seller] = $this->floor();

        $this->travelTo('2026-11-15 09:00:00');
        $this->open($seller, $device, 200)->assertCreated();

        $this->travelTo(self::MORNING);
        $this->actingAs($seller)->getJson("/api/shifts/current?device_id={$device->id}")
            ->assertJsonPath('stale', true);

        $this->travelTo('2026-11-15 20:00:00');
        $this->actingAs($seller)->getJson("/api/shifts/current?device_id={$device->id}")
            ->assertJsonPath('stale', false);
    }

    public function test_a_second_shift_cannot_open_on_a_till_that_already_has_one_today(): void
    {
        [$branch, $device, $seller] = $this->floor();
        $colleague = User::factory()->seller($branch)->create();

        $this->travelTo(self::MORNING);
        $first = $this->open($seller, $device, 100)->json('data.id');

        $this->travelTo('2026-11-16 13:00:00');
        $this->open($colleague, $device, 100)
            ->assertStatus(409)
            ->assertJsonPath('code', 'shift_already_open')
            ->assertJsonPath('context.shift_id', $first);

        $this->assertSame(1, Shift::count());
        $this->assertNull(Shift::findOrFail($first)->closed_at);
    }

    public function test_the_database_itself_allows_one_open_shift_per_device(): void
    {
        [$branch, $device, $seller] = $this->floor();

        Shift::factory()->create(['branch_id' => $branch->id, 'device_id' => $device->id, 'user_id' => $seller->id]);

        $this->expectException(UniqueConstraintViolationException::class);

        Shift::factory()->create(['branch_id' => $branch->id, 'device_id' => $device->id, 'user_id' => $seller->id]);
    }

    public function test_the_device_supplies_the_id_and_the_clock_and_a_replay_changes_nothing(): void
    {
        [, $device, $seller] = $this->floor();
        $id = (string) Str::uuid();

        $this->travelTo('2026-11-16 18:00:00'); // the server hears about it hours later

        $this->open($seller, $device, 200, ['id' => $id, 'opened_at' => '2026-11-16T09:05:00Z'])
            ->assertCreated()
            ->assertJsonPath('data.id', $id)
            ->assertJsonPath('data.opened_at', '2026-11-16T09:05:00+00:00');

        $this->assertSame('2026-11-16 18:00:00', Shift::findOrFail($id)->synced_at->toDateTimeString());

        // The same sync again: no second shift, no conflict.
        $this->open($seller, $device, 200, ['id' => $id, 'opened_at' => '2026-11-16T09:05:00Z'])
            ->assertCreated()
            ->assertJsonPath('data.id', $id);
        $this->assertSame(1, Shift::count());

        $this->close($seller, $id, 200, ['closed_at' => '2026-11-16T17:45:00Z'])
            ->assertOk()
            ->assertJsonPath('data.closed_at', '2026-11-16T17:45:00+00:00');
    }

    public function test_a_shift_cannot_be_closed_twice_or_before_it_opened(): void
    {
        [, $device, $seller] = $this->floor();

        $this->travelTo(self::MORNING);
        $id = $this->open($seller, $device, 100)->json('data.id');

        $this->close($seller, $id, 100, ['closed_at' => '2026-11-16T08:00:00Z'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'shift_closed_before_opened');

        $this->close($seller, $id, 100)->assertOk();
        $this->close($seller, $id, 999)->assertStatus(409)->assertJsonPath('code', 'shift_already_closed');

        // The first count stands.
        $this->assertSame('100.00', (string) Shift::findOrFail($id)->counted_cash);
    }

    public function test_closing_needs_a_count(): void
    {
        [, $device, $seller] = $this->floor();
        $id = $this->open($seller, $device, 100)->json('data.id');

        $this->actingAs($seller)->postJson("/api/shifts/{$id}/close", [])->assertStatus(422)->assertJsonValidationErrors('counted_cash');
    }

    public function test_only_managers_and_the_owner_list_shifts_and_a_manager_sees_their_own_branch(): void
    {
        [$branch, $device, $seller, $manager] = $this->floor();
        $otherBranch = Branch::factory()->create();
        $otherDevice = Device::factory()->create(['branch_id' => $otherBranch->id]);
        $otherSeller = User::factory()->seller($otherBranch)->create();

        $this->open($seller, $device, 100)->assertCreated();
        $this->open($otherSeller, $otherDevice, 100)->assertCreated();

        $this->actingAs($seller)->getJson('/api/shifts')->assertForbidden()->assertJsonPath('code', 'sales_not_allowed');

        $this->actingAs($manager)->getJson('/api/shifts')->assertOk()->assertJsonCount(1, 'data');

        $owner = User::factory()->owner()->create();
        $this->actingAs($owner)->getJson('/api/shifts')->assertOk()->assertJsonCount(2, 'data');
        $this->actingAs($owner)->getJson("/api/shifts?branch_id={$otherBranch->id}")->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($owner)->getJson("/api/shifts?user_id={$seller->id}")->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_another_businesss_till_and_shift_are_not_reachable(): void
    {
        [$branch, $device, $seller] = $this->floor();
        $id = $this->open($seller, $device, 100)->json('data.id');

        $this->tenant(); // a second business
        [, $theirDevice, $theirSeller] = $this->shopFloor();

        $this->open($theirSeller, $device, 100)->assertStatus(422)->assertJsonValidationErrors('device_id');
        $this->close($theirSeller, $id, 100)->assertNotFound();
    }

    public function test_a_till_with_shifts_cannot_be_deleted(): void
    {
        [$branch, $device, $seller] = $this->floor();
        $owner = User::factory()->owner()->create();

        $this->open($seller, $device, 100)->assertCreated();

        $this->actingAs($owner)->deleteJson("/api/devices/{$device->id}")
            ->assertStatus(422)
            ->assertJsonPath('code', 'has_shift_history');
    }
}
