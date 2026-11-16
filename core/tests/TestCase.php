<?php

namespace Tests;

use App\Actions\Selling\CreateInvoice;
use App\Enums\StockMovementType;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Device;
use App\Models\Invoice;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\StockLedger;
use App\Support\BusinessContext;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Make a business and make it the current tenant, so every factory after
     * this lands under it without business_id being passed around.
     */
    protected function tenant(array $attributes = []): Business
    {
        $business = Business::factory()->create($attributes);

        app(BusinessContext::class)->setBusiness($business);

        return $business;
    }

    /** Put stock on the shelf the only way the system allows: a movement. */
    protected function giveStock(ProductVariant $variant, Branch|string $branch, int $quantity): void
    {
        app(StockLedger::class)->post(
            variant: $variant,
            branchId: $branch instanceof Branch ? $branch->id : $branch,
            quantity: $quantity,
            type: StockMovementType::Purchase,
            note: 'test opening stock',
        );
    }

    /**
     * A branch with a till and a seller on it.
     *
     * @return array{0: Branch, 1: Device, 2: User}
     */
    protected function shopFloor(): array
    {
        $branch = Branch::factory()->create();
        $device = Device::factory()->create(['branch_id' => $branch->id]);
        $seller = User::factory()->seller($branch)->create();

        return [$branch, $device, $seller];
    }

    /** A branch manager on the given branch — who may issue returns. */
    protected function manager(Branch $branch): User
    {
        return User::factory()->branchManager($branch)->create();
    }

    /**
     * Ring up a sale without going through HTTP, for test setup.
     *
     * @param  array<int, array{variant_id: string, quantity: int}>  $items
     */
    protected function sell(User $seller, Device $device, array $items): Invoice
    {
        return app(CreateInvoice::class)->handle(
            seller: $seller,
            device: $device,
            items: $items,
        );
    }
}
