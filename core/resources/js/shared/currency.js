import { session } from './session.js'

/**
 * How a currency code is written next to an amount. The shop stores an ISO
 * code ("EGP"); the till shows the short local form where there is one and the
 * code itself where there is not, so an unlisted currency is still correct.
 */
const SYMBOLS = {
    EGP: 'ج.م',
    SAR: 'ر.س',
    AED: 'د.إ',
    KWD: 'د.ك',
    USD: '$',
    EUR: '€',
}

export const currencyLabel = (code) => SYMBOLS[code] ?? code ?? ''

/** The signed-in shop's currency, for screens that have a session. */
export const shopCurrency = () => currencyLabel(session.business?.currency ?? 'EGP')
