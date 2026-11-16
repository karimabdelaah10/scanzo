<?php

namespace App\Enums;

enum ShiftCloseKind: string
{
    /** Somebody counted the drawer. */
    case Counted = 'counted';

    /** Nobody closed it; the next opening on the same till closed it. No count, so no difference. */
    case Auto = 'auto';
}
