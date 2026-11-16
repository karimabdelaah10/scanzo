<?php

namespace App\Enums;

enum StockMovementType: string
{
    case Purchase = 'purchase';
    case Sale = 'sale';
    case Return = 'return';
    /** A voided invoice putting its pieces back. Kept apart from Return so the movements screen can tell them apart. */
    case Cancellation = 'cancellation';
    case Adjustment = 'adjustment';
    case TransferIn = 'transfer_in';
    case TransferOut = 'transfer_out';
    /** A transfer cancelled before arrival: the pieces going back to the source. */
    case TransferCancel = 'transfer_cancel';
    /** A shortage found later: the missing pieces entering the branch they really reached. */
    case TransferSettlement = 'transfer_settlement';

    /** Whether this type adds to stock or takes away from it. */
    public function isInbound(): bool
    {
        return match ($this) {
            self::Purchase, self::Return, self::Cancellation, self::TransferIn, self::TransferCancel, self::TransferSettlement => true,
            self::Sale, self::TransferOut => false,
            // An adjustment can go either way; its sign is set by the caller.
            self::Adjustment => true,
        };
    }
}
