<?php

namespace App\Enums;

enum ItemCondition: string
{
    case Good = 'good';
    case Damaged = 'damaged';

    public function restockable(): bool
    {
        return $this === self::Good;
    }
}
