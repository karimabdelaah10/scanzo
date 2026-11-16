import { reactive } from 'vue'
import { till, ApiError, hooks } from './api.js'

/**
 * Who is signed in, which shop, and which branch and till they are working on.
 *
 * The branch and till are kept in localStorage because a seller picks them
 * once and then uses the same phone on the same counter every day. They are
 * re-checked against the server's list on every start, so a till that was
 * switched off does not linger as a selection that fails at the moment of a
 * sale.
 */

const STORAGE_KEY = 'scanzo.workspace'

export const session = reactive({
    loading: true,
    user: null,
    business: null,
    branches: [],
    devices: [],
    // [{ value, label }] from the server's PaymentMethod enum, so the labels
    // are never typed twice.
    paymentMethods: [],
    // { state: 'active' | 'expiring' | 'expired' | 'none', days_left, ends_at }.
    // Only the dashboard's owner banner reads it: it informs, never blocks.
    subscription: null,
    branchId: null,
    deviceId: null,

    get signedIn() {
        return this.user !== null
    },

    /** A suspended shop: it can read everything and change nothing. */
    get readOnly() {
        return this.business?.read_only === true
    },

    get branch() {
        return this.branches.find((b) => b.id === this.branchId) ?? null
    },

    get device() {
        return this.devices.find((d) => d.id === this.deviceId) ?? null
    },

    get devicesInBranch() {
        return this.devices.filter((d) => d.branch_id === this.branchId)
    },

    get ready() {
        return this.signedIn && this.branchId !== null && this.deviceId !== null
    },
})

// Suspended mid-session: flip to read only now, from whichever response said
// so, instead of leaving a stale screen up until the next page load.
hooks.onSuspended = () => {
    if (session.business) session.business.read_only = true
}

function readStored() {
    try {
        return JSON.parse(localStorage.getItem(STORAGE_KEY) ?? '{}')
    } catch (e) {
        // A private window or blocked storage: a remembered counter is a
        // convenience, never a requirement.
        return {}
    }
}

function writeStored() {
    try {
        localStorage.setItem(
            STORAGE_KEY,
            JSON.stringify({ branchId: session.branchId, deviceId: session.deviceId }),
        )
    } catch (e) {
        // Ignore: see readStored.
    }
}

/**
 * Paint and remember the shop's brand. public/js/brand-boot.js already applied
 * the cached copy before this bundle loaded; this reconciles it with what the
 * server just said, and caches it (keyed by business id) for the next load.
 * Optional chaining: the boot script is a separate file and must never be
 * able to break signing in.
 */
function applyBrand(business) {
    if (!business?.id) return
    window.ScanzoBrand?.apply(business.brand)
    window.ScanzoBrand?.cache(business.id, business.brand)
}

function apply(payload) {
    session.user = payload.user
    session.business = payload.business
    applyBrand(payload.business)
    session.subscription = payload.subscription ?? null
    session.branches = payload.branches ?? []
    session.devices = payload.devices ?? []
    session.paymentMethods = payload.payment_methods ?? []

    const stored = readStored()

    // Only accept a remembered choice that still exists and is still active.
    session.branchId = session.branches.some((b) => b.id === stored.branchId)
        ? stored.branchId
        : (session.branches[0]?.id ?? null)

    const devices = session.devices.filter((d) => d.branch_id === session.branchId)

    session.deviceId = devices.some((d) => d.id === stored.deviceId)
        ? stored.deviceId
        : (devices[0]?.id ?? null)

    writeStored()
}

function clear() {
    session.user = null
    session.business = null
    session.subscription = null
    session.branches = []
    session.devices = []
    session.paymentMethods = []
    session.branchId = null
    session.deviceId = null
}

export async function startSession() {
    session.loading = true

    try {
        apply(await till.me())
    } catch (e) {
        if (e instanceof ApiError && e.isUnauthenticated) {
            clear()
        } else {
            throw e
        }
    } finally {
        session.loading = false
    }
}

/**
 * Re-read the shop's branches and tills after the owner changed them, without
 * the loading flag that would unmount the screen they are working on.
 */
export async function refreshWorkspace() {
    apply(await till.me())
}

/** Where each role works: a seller sells, everyone else runs the shop. */
export const homeFor = (user) => (user?.role === 'seller' ? '/till' : '/dashboard')

/**
 * `app` is the page the form is on ('/till' or '/dashboard'). If the role
 * belongs to the other app, leave for it. This runs after signing in only, so
 * an owner who opens the till on purpose is never bounced out of it.
 * `replace`, so Back does not return to the page that just redirected.
 */
export async function signIn(email, password, app) {
    apply(await till.login(email, password))

    const home = homeFor(session.user)

    if (app && home !== app) {
        window.location.replace(home)
    }
}

export async function signOut() {
    try {
        await till.logout()
    } finally {
        clear()
        // Sign-out forgets the shop's colours on this device. An expired
        // session (401 at start) deliberately does not: the sign-in screen
        // can still wear the shop's colours.
        window.ScanzoBrand?.clear()
    }
}

export function chooseBranch(branchId) {
    session.branchId = branchId

    const devices = session.devices.filter((d) => d.branch_id === branchId)
    session.deviceId = devices[0]?.id ?? null

    writeStored()
}

export function chooseDevice(deviceId) {
    session.deviceId = deviceId
    writeStored()
}
