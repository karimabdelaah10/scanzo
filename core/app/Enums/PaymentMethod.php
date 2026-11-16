<?php

namespace App\Enums;

enum PaymentMethod: string
{
    case Cash = 'cash';
    case Card = 'card';
    case Wallet = 'wallet';
    case Mixed = 'mixed';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'كاش',
            self::Card => 'فيزا',
            self::Wallet => 'محفظة',
            self::Mixed => 'مقسّم',
        };
    }
}
