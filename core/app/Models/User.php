<?php

namespace App\Models;

use App\Enums\Role;
use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use BelongsToBusiness, HasFactory, HasUuids, Notifiable;

    protected $fillable = [
        'business_id', 'branch_id', 'name', 'email', 'phone', 'password', 'role', 'is_active',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => Role::class,
            'is_active' => 'boolean',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function isOwner(): bool
    {
        return $this->role === Role::Owner;
    }

    public function isSeller(): bool
    {
        return $this->role === Role::Seller;
    }

    /** Owners work across every branch; everyone else only in their own. */
    public function canAccessBranch(string $branchId): bool
    {
        return $this->role->seesAllBranches() || $this->branch_id === $branchId;
    }

    public function canIssueReturns(): bool
    {
        return ! $this->isSeller() || $this->business->sellersMayReturn();
    }

    /**
     * A discount, on a line or on the whole invoice, is money the shop does not
     * take: sellers need the shop's say-so, one switch for both.
     */
    public function canGiveDiscounts(): bool
    {
        return ! $this->isSeller() || $this->business->sellersMayDiscount();
    }
}
