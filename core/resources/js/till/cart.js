import { reactive } from 'vue'
import { toMinor } from '../shared/money.js'

/**
 * The cart, in piastres.
 *
 * Totals are worked out in whole piastres rather than with decimals, for the
 * same reason the server does: 0.1 + 0.2 is not 0.3 in binary floating point,
 * and a till that is a piastre out at the end of the day is a till nobody
 * trusts. The server recalculates everything from the catalogue anyway — this
 * is only what the seller is shown before saving.
 */

export const DEFAULT_PAYMENT_METHOD = 'cash'

export const cart = reactive({
    lines: [],
    discount: 0,

    // Lives here, not in Cart.vue, so leaving the cart to scan another piece
    // does not silently put it back to cash.
    paymentMethod: DEFAULT_PAYMENT_METHOD,

    get count() {
        return this.lines.reduce((sum, line) => sum + line.quantity, 0)
    },

    /** Before any discount: list price × quantity. */
    get grossMinor() {
        return this.lines.reduce((sum, line) => sum + lineGrossMinor(line), 0)
    },

    /** What was taken off individual lines. */
    get lineDiscountsMinor() {
        return this.lines.reduce((sum, line) => sum + lineDiscountMinor(line), 0)
    },

    /**
     * After the line discounts, before the invoice discount. The invoice
     * discount is taken from this number — line first, invoice second, the
     * same order the server uses — so nothing is discounted twice.
     */
    get subtotalMinor() {
        return this.grossMinor - this.lineDiscountsMinor
    },

    /** A line discount bigger than its line; the server refuses it too. */
    get lineDiscountTooLarge() {
        return this.lines.some((line) => lineDiscountMinor(line) > lineGrossMinor(line))
    },

    get discountMinor() {
        return Math.max(0, Math.round(this.discount * 100))
    },

    /**
     * Line discounts plus the invoice discount are over what this seller may
     * give: a percentage of the gross total, before either. Same rule as the server.
     */
    discountOverLimit(maxPercent) {
        return this.lineDiscountsMinor + this.discountMinor > Math.round((this.grossMinor * maxPercent) / 100)
    },

    get totalMinor() {
        return Math.max(0, this.subtotalMinor - this.discountMinor)
    },

    get isEmpty() {
        return this.lines.length === 0
    },

    /** The discount cannot be more than the sale; the server refuses it too. */
    get discountTooLarge() {
        return this.discountMinor > this.subtotalMinor
    },
})

// Moved to shared/ so the dashboard's receipt can use them without the cart.
export { money, toMinor } from '../shared/money.js'

/**
 * Adds a scanned item, merging it into its existing line.
 *
 * `available` is what the branch had at the moment of the scan, so the line
 * can warn before the server refuses the whole sale.
 */
export function addToCart(variant, quantity = 1) {
    const existing = cart.lines.find((line) => line.variantId === variant.id)

    if (existing) {
        existing.quantity += quantity
        existing.available = variant.available
        return existing
    }

    const line = {
        variantId: variant.id,
        sku: variant.sku,
        name: variant.product_name,
        label: variant.label,
        priceMinor: toMinor(variant.price),
        quantity,
        available: variant.available,
        // What the seller typed, and how. Only the AMOUNT it works out to is
        // ever sent: a percentage is a way of typing, not something stored.
        discountMode: 'amount',
        discountValue: null,
    }

    cart.lines.push(line)

    return line
}

export function lineGrossMinor(line) {
    return line.priceMinor * line.quantity
}

/**
 * The line's discount as an amount in piastres, whichever way it was typed.
 * A percentage follows the quantity (20% of two pieces is twice 20% of one),
 * and is turned into money here, at the edge, rounded half up like the server.
 */
export function lineDiscountMinor(line) {
    const value = Number(line.discountValue) || 0

    if (value <= 0) return 0

    return line.discountMode === 'percent'
        ? Math.round((lineGrossMinor(line) * value) / 100)
        : toMinor(value)
}

export function lineNetMinor(line) {
    return lineGrossMinor(line) - lineDiscountMinor(line)
}

/** Over what this seller may give: the cap is a percentage of the line. */
export function lineDiscountOverLimit(line, maxPercent) {
    return lineDiscountMinor(line) > Math.round((lineGrossMinor(line) * maxPercent) / 100)
}

export function setQuantity(line, quantity) {
    line.quantity = Math.max(1, quantity)
}

export function removeLine(line) {
    cart.lines.splice(cart.lines.indexOf(line), 1)
}

export function clearCart() {
    cart.lines.splice(0, cart.lines.length)
    cart.discount = 0
    // A new invoice starts from the default, not the last customer's choice.
    cart.paymentMethod = DEFAULT_PAYMENT_METHOD
}

/** What the server is sent: ids, quantities and line discounts as money. Never prices. */
export function cartPayload() {
    return cart.lines.map((line) => ({
        variant_id: line.variantId,
        quantity: line.quantity,
        // Money, never a percentage. Left out when there is none.
        ...(lineDiscountMinor(line) > 0 ? { discount_amount: lineDiscountMinor(line) / 100 } : {}),
    }))
}
