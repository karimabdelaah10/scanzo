import { reactive } from 'vue'
import { portion, toMinor } from '../shared/money.js'

/**
 * What the customer gets back for returning `quantity` more pieces of a line:
 * their share of what they PAID (`line_total` after the line discount, minus
 * the line's stored share of the invoice discount), not of the list price.
 * Same arithmetic as InvoiceItem::refundMinorFor on the server, which is what
 * actually decides.
 */
export function refundForLine(line, quantity) {
    const paid = toMinor(line.line_total) - toMinor(line.invoice_discount_share)

    return portion(paid, line.returned_quantity + quantity, line.quantity)
        - portion(paid, line.returned_quantity, line.quantity)
}

/**
 * A return being put together.
 *
 * Kept apart from the cart because the two can be open at once: an exchange is
 * a return and a sale, and the seller builds both before either is saved.
 */

export const returnDraft = reactive({
    invoice: null,
    windowDays: 0,
    withinWindow: true,
    mayOverrideWindow: false,
    overrideWindow: false,
    reason: '',

    /** invoice_item_id → { quantity, condition } */
    picked: {},

    get isEmpty() {
        return Object.values(this.picked).every((p) => p.quantity === 0)
    },

    get lines() {
        return (this.invoice?.items ?? []).map((item) => ({
            ...item,
            pick: this.picked[item.id] ?? { quantity: 0, condition: 'good' },
        }))
    },

    get refundMinor() {
        return this.lines.reduce((sum, line) => sum + refundForLine(line, line.pick.quantity), 0)
    },

    /** Past the window, nothing can be saved without a manager accepting it. */
    get blockedByWindow() {
        return !this.withinWindow && !this.overrideWindow
    },

    get canSave() {
        return this.invoice !== null && !this.isEmpty && !this.blockedByWindow
    },
})

export function startReturn(invoice, meta) {
    returnDraft.invoice = invoice
    returnDraft.windowDays = meta.return_window_days
    returnDraft.withinWindow = meta.within_return_window
    returnDraft.mayOverrideWindow = meta.may_override_window
    returnDraft.overrideWindow = false
    returnDraft.reason = ''

    returnDraft.picked = {}

    for (const item of invoice.items ?? []) {
        returnDraft.picked[item.id] = { quantity: 0, condition: 'good' }
    }
}

export function setPicked(item, quantity) {
    const pick = returnDraft.picked[item.id]
    if (!pick) return

    // Never more than the invoice still has outstanding; the server refuses it
    // too, but the seller should not be able to build an invalid return.
    pick.quantity = Math.min(Math.max(0, quantity), item.returnable_quantity)
}

export function setCondition(item, condition) {
    const pick = returnDraft.picked[item.id]
    if (pick) pick.condition = condition
}

export function clearReturn() {
    returnDraft.invoice = null
    returnDraft.picked = {}
    returnDraft.overrideWindow = false
    returnDraft.reason = ''
}

/** What the server is sent. A damaged piece does not go back on the shelf. */
export function returnPayload() {
    return returnDraft.lines
        .filter((line) => line.pick.quantity > 0)
        .map((line) => ({
            invoice_item_id: line.id,
            quantity: line.pick.quantity,
            condition: line.pick.condition,
            restock: line.pick.condition !== 'damaged',
        }))
}
