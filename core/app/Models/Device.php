<?php

namespace App\Models;

use App\Enums\DeviceType;
use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A registered till. Its `code` is the invoice number prefix, which is what
 * keeps numbers unique when several devices sell offline at once.
 */
class Device extends Model
{
    use BelongsToBusiness, HasFactory, HasUuids;

    protected $fillable = [
        'business_id', 'branch_id', 'name', 'code', 'type', 'last_sync_at', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'type' => DeviceType::class,
            'last_sync_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * A device that has not reached the server within the subscription's grace
     * period must stop selling, otherwise a shop could run indefinitely
     * without paying.
     */
    public function isWithinOfflineGrace(int $graceDays): bool
    {
        if ($this->last_sync_at === null) {
            return false;
        }

        return $this->last_sync_at->diffInDays(now()) < $graceDays;
    }
}
