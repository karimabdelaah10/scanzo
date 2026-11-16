<?php

namespace App\Enums;

enum BusinessStatus: string
{
    case Trial = 'trial';
    case Active = 'active';
    case Suspended = 'suspended';

    public function label(): string
    {
        return match ($this) {
            self::Trial => 'تجربة',
            self::Active => 'فعّال',
            self::Suspended => 'موقوف',
        };
    }

    /**
     * Suspended means read only: the shop can look at its data and nothing
     * else. EnsureBusinessIsWritable enforces it for every request; the sale
     * and return actions check canSell() again as a second line of defence.
     */
    public function isReadOnly(): bool
    {
        return $this === self::Suspended;
    }

    public function canSell(): bool
    {
        return ! $this->isReadOnly();
    }
}
