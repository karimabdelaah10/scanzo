<?php

namespace Tests\Feature;

use App\Enums\PaymentMethod;
use App\Models\Branch;
use App\Models\Device;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceHistoryTest extends TestCase
{
    use RefreshDatabase;

    private function changeCurrency(string $code): void
    {
        $business = app(\App\Support\BusinessContext::class)->business();
        $settings = $business->settings;
        $settings['currency'] = $code;
        $business->update(['settings' => $settings]);
    }

    public function test_an_old_invoice_keeps_the_currency_it_was_sold_in(): void
    {
        $this->tenant();
        [$branch, $device, $seller] = $this->shopFloor();
        $variant = ProductVariant::factory()->create(['price' => 100]);
        $this->giveStock($variant, $branch, 10);

        $old = $this->sell($seller, $device, [['variant_id' => $variant->id, 'quantity' => 1]]);

        $this->changeCurrency('SAR');

        $new = $this->sell($seller->fresh(), $device, [['variant_id' => $variant->id, 'quantity' => 1]]);

        $this->actingAs($this->manager($branch))
            ->getJson("/api/invoices/{$old->id}")
            ->assertOk()
            ->assertJsonPath('data.currency', 'EGP')
            ->assertJsonMissingPath('data.business.currency');

        $this->actingAs($this->manager($branch))
            ->getJson("/api/invoices/{$new->id}")
            ->assertJsonPath('data.currency', 'SAR');
    }

    public function test_the_list_carries_piece_count_and_return_flag_and_a_manager_sees_only_their_branch(): void
    {
        $this->tenant();
        [$branch, $device, $seller] = $this->shopFloor();
        [$otherBranch, $otherDevice, $otherSeller] = $this->shopFloor();

        $variant = ProductVariant::factory()->create(['price' => 100]);
        $this->giveStock($variant, $branch, 10);
        $this->giveStock($variant, $otherBranch, 10);

        $mine = $this->sell($seller, $device, [['variant_id' => $variant->id, 'quantity' => 3]]);
        $this->sell($otherSeller, $otherDevice, [['variant_id' => $variant->id, 'quantity' => 1]]);

        $manager = $this->manager($branch);

        $this->actingAs($manager)->getJson('/api/invoices')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mine->id)
            ->assertJsonPath('data.0.items_count', 3)
            ->assertJsonPath('data.0.has_returns', false)
            ->assertJsonPath('data.0.seller.name', $seller->name);

        // Asking for the other branch by id changes nothing: the server decides.
        $this->actingAs($manager)->getJson('/api/invoices?branch_id='.$otherBranch->id)
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_the_summary_splits_by_payment_method_and_seller_and_keeps_returns_apart(): void
    {
        $this->tenant();
        [$branch, $device, $seller] = $this->shopFloor();
        $second = User::factory()->seller($branch)->create();

        $variant = ProductVariant::factory()->create(['price' => 100]);
        $this->giveStock($variant, $branch, 20);

        $this->sell($seller, $device, [['variant_id' => $variant->id, 'quantity' => 2]]);
        $card = $this->sell($second, $device, [['variant_id' => $variant->id, 'quantity' => 1]]);
        $card->update(['payment_method' => PaymentMethod::Card]);

        $response = $this->actingAs($this->manager($branch))
            ->getJson('/api/invoices/summary?date='.now()->toDateString())
            ->assertOk()
            ->assertJsonPath('data.invoices', 2)
            ->assertJsonPath('data.total', '300.00')
            ->assertJsonPath('data.average', '150.00')
            ->assertJsonPath('data.returns.count', 0)
            ->assertJsonPath('data.returns.total', '0.00');

        $methods = collect($response->json('data.by_payment_method'))->pluck('total', 'method');
        $this->assertSame('200.00', $methods['cash']);
        $this->assertSame('100.00', $methods['card']);

        $this->assertCount(2, $response->json('data.by_seller'));
    }

    public function test_a_seller_lists_and_opens_only_their_own_invoices(): void
    {
        $this->tenant();
        [$branch, $device, $seller] = $this->shopFloor();
        $colleague = User::factory()->seller($branch)->create();

        $variant = ProductVariant::factory()->create(['price' => 100]);
        $this->giveStock($variant, $branch, 10);

        $mine = $this->sell($seller, $device, [['variant_id' => $variant->id, 'quantity' => 1]]);
        $theirs = $this->sell($colleague, $device, [['variant_id' => $variant->id, 'quantity' => 1]]);

        $list = $this->actingAs($seller)->getJson('/api/invoices')->assertOk();
        $this->assertSame([$mine->id], collect($list->json('data'))->pluck('id')->all());

        // Asking for the colleague by id changes nothing.
        $asked = $this->actingAs($seller)->getJson('/api/invoices?user_id='.$colleague->id)->assertOk();
        $this->assertSame([$mine->id], collect($asked->json('data'))->pluck('id')->all());

        $this->actingAs($seller)->getJson("/api/invoices/{$mine->id}")->assertOk();
        $this->actingAs($seller)->getJson("/api/invoices/{$theirs->id}")->assertForbidden();

        // A manager still sees both.
        $all = $this->actingAs($this->manager($branch))->getJson('/api/invoices')->assertOk();
        $this->assertCount(2, $all->json('data'));
    }

    public function test_a_seller_cannot_read_the_summary(): void
    {
        $this->tenant();
        [, , $seller] = $this->shopFloor();

        $this->actingAs($seller)->getJson('/api/invoices/summary')
            ->assertForbidden()
            ->assertJsonPath('code', 'sales_not_allowed');
    }
}
