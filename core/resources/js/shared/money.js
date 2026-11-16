/**
 * Money formatting both apps share. Amounts travel as decimal strings ("12.50")
 * and are worked on as whole piastres; see till/cart.js for why.
 */

export function money(minor) {
    return (minor / 100).toLocaleString('en-US', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    })
}

/**
 * The part of `totalMinor` that belongs to the first `units` of `of` equal
 * units, rounded half up. The same function as Money::portion in PHP — keep
 * them identical, the till previews what the server will refund.
 */
export function portion(totalMinor, units, of) {
    if (of <= 0) return 0

    return Math.floor((totalMinor * units * 2 + of) / (2 * of))
}

export function toMinor(amount) {
    return Math.round(Number(amount ?? 0) * 100)
}
