<?php

namespace App\Support;

/**
 * Money as integer piastres.
 *
 * Deliberately not bcmath: that extension is not compiled into the stock PHP
 * images and a missing one is a fatal error, not a degraded total. Integer
 * arithmetic is exact for every amount a shop will ever ring up, and the
 * decimal strings the database and the API use are produced only at the edges.
 */
class Money
{
    /** "450.00", 450, 450.0 → 45000 */
    public static function toMinor(string|int|float|null $amount): int
    {
        if ($amount === null || $amount === '') {
            return 0;
        }

        return (int) round(((float) $amount) * 100);
    }

    /** 45000 → "450.00" */
    public static function toDecimal(int $minor): string
    {
        return number_format($minor / 100, 2, '.', '');
    }

    /**
     * The part of `$totalMinor` that belongs to the first `$units` of `$of`
     * equal units, rounded half up.
     *
     * Used to refund a discounted line. 350 paid for 3 pieces is not a whole
     * number each, so a return is the difference between two cumulative
     * portions: whatever order the pieces come back in, the refunds add up to
     * exactly what was paid, never a piastre more or less.
     * (till/returns.js has the same function; keep them identical.)
     */
    public static function portion(int $totalMinor, int $units, int $of): int
    {
        if ($of <= 0) {
            return 0;
        }

        return intdiv($totalMinor * $units * 2 + $of, 2 * $of);
    }

    /**
     * Splits `$totalMinor` across `$weights` in proportion, to the piastre.
     * The shares always add up to `$totalMinor` exactly: each one is the
     * difference between two cumulative portions, the same trick as a return,
     * so no piastre is lost or invented to rounding. Keys are kept.
     *
     * Used to spread an invoice discount over its lines at sale time.
     * A zero total weight gives every share zero.
     *
     * @param  array<int|string, int>  $weights
     * @return array<int|string, int>
     */
    public static function allocate(int $totalMinor, array $weights): array
    {
        $sum = array_sum($weights);
        $shares = [];
        $running = 0;
        $previous = 0;

        foreach ($weights as $key => $weight) {
            $running += $weight;
            $cumulative = self::portion($totalMinor, $running, $sum);
            $shares[$key] = $cumulative - $previous;
            $previous = $cumulative;
        }

        return $shares;
    }

    public static function multiply(int $minor, int $quantity): int
    {
        return $minor * $quantity;
    }
}
