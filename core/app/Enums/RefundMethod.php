<?php

namespace App\Enums;

enum RefundMethod: string
{
    case Cash = 'cash';
    case Exchange = 'exchange';
    case StoreCredit = 'store_credit';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'استرداد كاش',
            self::Exchange => 'استبدال',
            self::StoreCredit => 'رصيد للعميل',
        };
    }
}
