<?php

namespace App\Support;

use App\Models\Device;

/**
 * Till codes: A1, A2 … A9, B1 …
 *
 * The code is the invoice number prefix, so it has to be short and unique
 * within the business. A shop should not have to invent it, and a shop that
 * invented the same one twice would get two tills numbering invoices
 * identically — which is exactly what offline selling cannot survive.
 */
class DeviceCode
{
    public static function next(): string
    {
        // Device is scoped to the current business, so this reads the codes
        // taken in this shop only — the same scope the unique index has.
        $taken = Device::pluck('code')
            ->map(fn ($code) => strtoupper($code))
            ->all();

        foreach (range('A', 'Z') as $letter) {
            foreach (range(1, 9) as $digit) {
                $code = $letter.$digit;

                if (! in_array($code, $taken, true)) {
                    return $code;
                }
            }
        }

        // 234 tills in one business is not a real shop. Let the unique index
        // speak if it ever happens.
        return 'Z9';
    }
}
