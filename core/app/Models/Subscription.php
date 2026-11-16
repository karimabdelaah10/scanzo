<?php

namespace App\Models;

use App\Enums\SubscriptionStatus;
use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Subscription extends Model
{
    use BelongsToBusiness, HasFactory, HasUuids;

    protected $fillable = [
        'business_id', 'plan', 'status', 'starts_at', 'ends_at',
        'offline_grace_days', 'max_branches', 'max_users', 'max_devices', 'note',
    ];

    protected function casts(): array
    {
        return [
            'status' => SubscriptionStatus::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'offline_grace_days' => 'integer',
        ];
    }

    public function isActive(): bool
    {
        return $this->status === SubscriptionStatus::Active
            && ($this->ends_at === null || $this->ends_at->isFuture());
    }

    /**
     * Whole calendar days until the last day, 0 on that day itself. Null when
     * there is no end date, or when it is already over.
     */
    public function daysLeft(): ?int
    {
        if ($this->ends_at === null || ! $this->isActive()) {
            return null;
        }

        return (int) now()->startOfDay()->diffInDays($this->ends_at->copy()->startOfDay());
    }

    /**
     * 'active', 'expiring' (inside the warning window) or 'expired'.
     *
     * This only ever informs: /api/me shows it to the owner and the platform
     * panel filters on it. It must not be used to refuse a sale.
     */
    public function state(): string
    {
        if (! $this->isActive()) {
            return 'expired';
        }

        $left = $this->daysLeft();

        return $left !== null && $left <= (int) config('scanzo.subscription_warning_days', 7)
            ? 'expiring'
            : 'active';
    }
}
