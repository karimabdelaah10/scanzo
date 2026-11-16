<?php

namespace App\Enums;

enum Role: string
{
    case Owner = 'owner';
    case BranchManager = 'branch_manager';
    case Seller = 'seller';

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'مالك',
            self::BranchManager => 'مدير فرع',
            self::Seller => 'بائع',
        };
    }

    /** Owners see every branch; everyone else is tied to theirs. */
    public function seesAllBranches(): bool
    {
        return $this === self::Owner;
    }

    public function seesCost(): bool
    {
        return $this !== self::Seller;
    }
}
