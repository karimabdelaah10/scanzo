<?php

namespace App\Models;

use App\Enums\ShiftCloseKind;
use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One till's drawer, from the opening float to the closing count.
 * See the migration for why the id and the timestamps come from the device.
 */
class Shift extends Model
{
    use BelongsToBusiness, HasFactory, HasUuids;

    protected $fillable = [
        'business_id', 'branch_id', 'device_id', 'user_id', 'closed_by',
        'opening_float', 'opened_at', 'closed_at',
        'expected_cash', 'counted_cash', 'difference', 'close_kind', 'note',
        'open_device_id', 'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'opening_float' => 'decimal:2',
            'expected_cash' => 'decimal:2',
            'counted_cash' => 'decimal:2',
            'difference' => 'decimal:2',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'synced_at' => 'datetime',
            'close_kind' => ShiftCloseKind::class,
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function opener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function isOpen(): bool
    {
        return $this->closed_at === null;
    }
}
