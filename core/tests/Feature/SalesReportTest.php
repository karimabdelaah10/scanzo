<?php

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesReportTest extends TestCase
{
    use RefreshDatabase;

    private function range(string $from, string $to, array $extra = []): string
    {
        return '/api/reports/sales?'.http_build_query(['from' => $from, 'to' => $to] + $extra);
    }

    public function test_the_range_includes_both_end_days_and_nothing_outside_them(): void
    {
        $this->tenant();
        [$branch, $device, $seller] = $this->shopFloor();
        $variant = ProductVariant::factory()->create(['price' => 100]);
        $this->giveStock($variant, $branch, 50);

        $line = [['variant_id' => $variant->id, 'quantity' => 1]];
        $dates = ['2026-03-09 12:00', '2026-03-10 12:00', '2026-03-12 12:00', '2026-03-13 12:00'];

        foreach ($dates as $date) {
            $this->sell($seller, $device, $line)->update(['issued_at' => $date]);
        }

        $this->actingAs($this->manager($branch))
            ->getJson($this->range('2026-03-10', '2026-03-12'))
            ->assertOk()
            ->assertJsonPath('data.invoices', 2)
            ->assertJsonPath('data.total', '200.00')
            ->assertJsonPath('data.average', '100.00');
    }

    public function test_the_range_follows_the_callers_day_not_utc(): void
    {
        $this->tenant();
        [$branch, $device, $seller] = $this->shopFloor();
        $variant = ProductVariant::factory()->create(['price' => 100]);
        $this->giveStock($variant, $branch, 5);

        // 22:30 UTC on the 9th is 00:30 on the 10th in Cairo (UTC+2 in March).
        $this->sell($seller, $device, [['variant_id' => $variant->id, 'quantity' => 1]])
            ->update(['issued_at' => '2026-03-09 22:30:00']);

        $manager = $this->manager($branch);

        $this->actingAs($manager)
            ->getJson($this->range('2026-03-10', '2026-03-10', ['tz' => 'Africa/Cairo']))
            ->assertJsonPath('data.invoices', 1);

        $this->actingAs($manager)
            ->getJson($this->range('2026-03-10', '2026-03-10', ['tz' => 'UTC']))
            ->assertJsonPath('data.invoices', 0);
    }

    public function test_voided_invoices_are_in_no_total_and_no_ranking(): void
    {
        $this->tenant();
        [$branch, $device, $seller] = $this->shopFloor();
        $variant = ProductVariant::factory()->create(['price' => 100]);
        $this->giveStock($variant, $branch, 50);

        $this->sell($seller, $device, [['variant_id' => $variant->id, 'quantity' => 2]]);
        $this->sell($seller, $device, [['variant_id' => $variant->id, 'quantity' => 5]])
            ->update(['status' => InvoiceStatus::Voided]);

        $res = $this->actingAs($this->manager($branch))
            ->getJson($this->range(now()->toDateString(), now()->toDateString()))
            ->assertOk()
            ->assertJsonPath('data.invoices', 1)
            ->assertJsonPath('data.total', '200.00')
            ->assertJsonPath('data.top_by_quantity.0.quantity', 2)
            ->assertJsonPath('data.top_by_value.0.total', '200.00');

        $this->assertCount(1, $res->json('data.by_seller'));
    }

    public function test_top_sellers_are_ranked_by_quantity_and_by_value_separately(): void
    {
        $this->tenant();
        [$branch, $device, $seller] = $this->shopFloor();

        $cheap = ProductVariant::factory()->create(['price' => 10]);
        $dear = ProductVariant::factory()->create(['price' => 500]);
        $this->giveStock($cheap, $branch, 100);
        $this->giveStock($dear, $branch, 100);

        $this->sell($seller, $device, [
            ['variant_id' => $cheap->id, 'quantity' => 20],
            ['variant_id' => $dear->id, 'quantity' => 2],
        ]);

        $res = $this->actingAs($this->manager($branch))
            ->getJson($this->range(now()->toDateString(), now()->toDateString()))
            ->assertOk();

        $this->assertSame($cheap->sku, $res->json('data.top_by_quantity.0.sku'));
        $this->assertSame($dear->sku, $res->json('data.top_by_value.0.sku'));
        $this->assertSame('1000.00', $res->json('data.top_by_value.0.total'));
    }

    public function test_per_seller_figures_carry_count_total_and_average(): void
    {
        $this->tenant();
        [$branch, $device, $seller] = $this->shopFloor();
        $other = User::factory()->seller($branch)->create();
        $variant = ProductVariant::factory()->create(['price' => 100]);
        $this->giveStock($variant, $branch, 50);

        $this->sell($seller, $device, [['variant_id' => $variant->id, 'quantity' => 1]]);
        $this->sell($seller, $device, [['variant_id' => $variant->id, 'quantity' => 3]]);
        $this->sell($other, $device, [['variant_id' => $variant->id, 'quantity' => 1]]);

        $rows = collect($this->actingAs($this->manager($branch))
            ->getJson($this->range(now()->toDateString(), now()->toDateString()))
            ->json('data.by_seller'))->keyBy('user_id');

        $this->assertSame(2, $rows[$seller->id]['invoices']);
        $this->assertSame('400.00', $rows[$seller->id]['total']);
        $this->assertSame('200.00', $rows[$seller->id]['average']);
        $this->assertSame('100.00', $rows[$other->id]['total']);
    }

    public function test_returns_are_reported_apart_and_not_taken_off_the_sales(): void
    {
        $this->tenant();
        [$branch, $device, $seller] = $this->shopFloor();
        $manager = $this->manager($branch);
        $variant = ProductVariant::factory()->create(['price' => 100]);
        $this->giveStock($variant, $branch, 10);

        $invoice = $this->sell($seller, $device, [['variant_id' => $variant->id, 'quantity' => 2]]);

        $this->actingAs($manager)->postJson('/api/returns', [
            'device_id' => $device->id,
            'invoice_id' => $invoice->id,
            'items' => [['invoice_item_id' => $invoice->items()->firstOrFail()->id, 'quantity' => 1]],
            'refund_method' => 'cash',
        ])->assertCreated();

        $this->actingAs($manager)
            ->getJson($this->range(now()->toDateString(), now()->toDateString()))
            ->assertOk()
            ->assertJsonPath('data.total', '200.00')
            ->assertJsonPath('data.returns.count', 1)
            ->assertJsonPath('data.returns.total', '100.00')
            ->assertJsonPath('data.top_by_quantity.0.quantity', 2)
            ->assertJsonPath('data.top_by_quantity.0.returned', 1);
    }

    public function test_a_manager_sees_their_branch_only_and_the_owner_sees_each_branch(): void
    {
        $this->tenant();
        [$branch, $device, $seller] = $this->shopFloor();
        [$other, $otherDevice, $otherSeller] = $this->shopFloor();
        $variant = ProductVariant::factory()->create(['price' => 100]);
        $this->giveStock($variant, $branch, 10);
        $this->giveStock($variant, $other, 10);

        $this->sell($seller, $device, [['variant_id' => $variant->id, 'quantity' => 1]]);
        $this->sell($otherSeller, $otherDevice, [['variant_id' => $variant->id, 'quantity' => 4]]);

        $today = $this->range(now()->toDateString(), now()->toDateString());

        // Asking for the other branch by id changes nothing for a manager.
        $mine = $this->actingAs($this->manager($branch))
            ->getJson($today.'&branch_id='.$other->id)
            ->assertOk()
            ->assertJsonPath('data.invoices', 1)
            ->assertJsonPath('data.total', '100.00');
        $this->assertSame([], $mine->json('data.by_branch'));

        $owner = User::factory()->owner()->create();

        $all = $this->actingAs($owner)->getJson($today)
            ->assertOk()
            ->assertJsonPath('data.invoices', 2)
            ->assertJsonPath('data.total', '500.00');
        $this->assertCount(2, $all->json('data.by_branch'));

        $this->actingAs($owner)->getJson($today.'&branch_id='.$other->id)
            ->assertJsonPath('data.total', '400.00');
    }

    public function test_a_seller_cannot_read_reports_and_a_bad_range_is_refused(): void
    {
        $this->tenant();
        [$branch, , $seller] = $this->shopFloor();

        $this->actingAs($seller)->getJson($this->range('2026-03-01', '2026-03-02'))
            ->assertForbidden()
            ->assertJsonPath('code', 'sales_not_allowed');

        $manager = $this->manager($branch);

        $this->actingAs($manager)->getJson($this->range('2026-03-05', '2026-03-01'))
            ->assertUnprocessable()->assertJsonValidationErrors('to');

        $this->actingAs($manager)->getJson($this->range('2020-01-01', '2026-03-01'))
            ->assertUnprocessable()->assertJsonValidationErrors('to');

        $this->actingAs($manager)->getJson('/api/reports/sales')
            ->assertUnprocessable()->assertJsonValidationErrors(['from', 'to']);
    }
}
