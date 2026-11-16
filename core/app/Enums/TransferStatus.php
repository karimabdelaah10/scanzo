<?php

namespace App\Enums;

enum TransferStatus: string
{
    /** Left the source; in no branch's balance until the target confirms. */
    case InTransit = 'in_transit';
    case Received = 'received';
    /** The source took it back before anyone confirmed. */
    case Cancelled = 'cancelled';
}
