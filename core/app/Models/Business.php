<?php

namespace App\Models;

use App\Enums\BusinessStatus;
use App\Support\Labels\LabelTemplate;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The tenant. Not scoped by BusinessScope — the platform admin panel queries
 * across all of them.
 */
class Business extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'name', 'slug', 'status', 'phone', 'tax_number', 'settings',
    ];

    protected function casts(): array
    {
        return [
            'status' => BusinessStatus::class,
            'settings' => 'array',
        ];
    }

    public function subscription(): HasOne
    {
        return $this->hasOne(Subscription::class)->latestOfMany();
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /** A setting from the `settings` json column, with a default. */
    public function setting(string $key, mixed $default = null): mixed
    {
        return data_get($this->settings, $key, $default);
    }

    public function returnWindowDays(): int
    {
        return (int) $this->setting('returns.window_days', 14);
    }

    /** How long after issuing an invoice may still be voided. Read by VoidInvoice. */
    public function voidWindowHours(): int
    {
        return (int) $this->setting('voids.window_hours', 24);
    }

    public function sellersMayReturn(): bool
    {
        return (bool) $this->setting('returns.sellers_allowed', false);
    }

    /**
     * One switch for every discount, line and invoice. Read by
     * User::canGiveDiscounts(), which CreateInvoice asks. (Stored under
     * `discounts`; it was `line_discounts` until the migration that moved it.)
     */
    public function sellersMayDiscount(): bool
    {
        return (bool) $this->setting('discounts.sellers_allowed', false);
    }

    /**
     * The most a seller may take off, as a percent: of a line for a line
     * discount, of the subtotal for the invoice discount. Managers and owners
     * have no cap. Read by CreateInvoice.
     */
    public function discountMaxPercent(): int
    {
        return (int) $this->setting('discounts.max_percent', 20);
    }

    /**
     * Days a transfer may stay in transit before the screen marks it late.
     * Read by StockTransferResource (`is_late`) and StockTransferController.
     */
    public function transferLateAfterDays(): int
    {
        return max(1, (int) $this->setting('transfers.late_after_days', 2));
    }

    public function allowsReturnWithoutInvoice(): bool
    {
        return (bool) $this->setting('returns.without_invoice', false);
    }

    /** ISO 4217 code, as the owner set it. */
    public function currency(): string
    {
        return (string) $this->setting('currency', 'EGP');
    }

    /** Anything stored that is not a template (older values) falls back to the default. */
    public function labelTemplate(): LabelTemplate
    {
        return LabelTemplate::tryFrom((string) $this->setting('labels.size')) ?? LabelTemplate::Roll40x30;
    }

    /** Whether a scan drops straight into the cart or opens the item first. */
    public function quickScan(): bool
    {
        return (bool) $this->setting('selling.quick_scan', false);
    }
}
