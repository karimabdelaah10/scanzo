<?php

namespace App\Enums;

enum InvoiceStatus: string
{
    case Completed = 'completed';
    case Voided = 'voided';
}
