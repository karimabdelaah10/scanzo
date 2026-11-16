<?php

namespace App\Support;

use Carbon\CarbonInterface;
use DateTimeZone;
use Illuminate\Http\Request;

/**
 * The shop's calendar days, turned into the UTC instants the database stores.
 *
 * Timestamps are stored in UTC, and a sale at 1am in Cairo belongs to the
 * shop's day, not to the previous UTC one. The screen sends its own `tz`; an
 * unknown one is ignored. One place, so a single day and a range can never
 * disagree about where a day starts.
 */
class ShopDays
{
    public static function timezone(Request $request): string
    {
        $tz = $request->input('tz');

        return is_string($tz) && in_array($tz, DateTimeZone::listIdentifiers(), true)
            ? $tz
            : config('app.timezone');
    }

    /**
     * Start of the first day to end of the last one, both inclusive.
     *
     * @return array{0: CarbonInterface, 1: CarbonInterface}
     */
    public static function between(Request $request, string $fromKey, string $toKey): array
    {
        $tz = self::timezone($request);
        $store = config('app.timezone');

        return [
            $request->date($fromKey, 'Y-m-d', $tz)->startOfDay()->setTimezone($store),
            $request->date($toKey, 'Y-m-d', $tz)->endOfDay()->setTimezone($store),
        ];
    }
}
