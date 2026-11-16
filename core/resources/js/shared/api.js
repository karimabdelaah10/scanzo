/**
 * The one place that talks to the API.
 *
 * Authentication is the session cookie, so there is no token to store or
 * refresh. Every call needs the CSRF token, which the page puts in a meta tag.
 *
 * Errors arrive in one shape — {message, code, context} — and this turns them
 * into an ApiError carrying all three, so a screen can react to the specific
 * case (mark the one line that is out of stock) instead of showing a generic
 * failure.
 */

export class ApiError extends Error {
    constructor(message, { status, code = null, context = {}, errors = {} } = {}) {
        super(message)
        this.name = 'ApiError'
        this.status = status
        this.code = code
        this.context = context
        // Laravel's validation errors, keyed by field.
        this.errors = errors
    }

    get isValidation() {
        return this.status === 422 && Object.keys(this.errors).length > 0
    }

    get isUnauthenticated() {
        return this.status === 401
    }

    /** The first message for a field, for showing under an input. */
    fieldError(field) {
        const messages = this.errors[field]
        return Array.isArray(messages) ? messages[0] : null
    }
}

/**
 * Hooks the session layer fills in. api.js cannot import session.js (session
 * imports this file), so a response that changes the app's state is reported
 * through here instead.
 */
export const hooks = {
    // Called when any response says the shop is suspended.
    onSuspended: null,
}

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.content ?? ''
}

function buildUrl(path, params) {
    const url = new URL(path.startsWith('/') ? path : `/${path}`, window.location.origin)

    Object.entries(params ?? {}).forEach(([key, value]) => {
        if (value === null || value === undefined || value === '') return

        // Repeated keys for arrays, which is what Laravel reads as an array.
        if (Array.isArray(value)) {
            value.forEach((item) => url.searchParams.append(`${key}[]`, item))
        } else {
            url.searchParams.set(key, value)
        }
    })

    return url.pathname + url.search
}

async function request(method, path, { params, body } = {}) {
    let response

    // A file goes as multipart, and the browser must set that Content-Type
    // itself (it carries the boundary); everything else is JSON.
    const isForm = typeof FormData !== 'undefined' && body instanceof FormData

    try {
        response = await fetch(buildUrl(path, params), {
            method,
            headers: {
                Accept: 'application/json',
                ...(isForm ? {} : { 'Content-Type': 'application/json' }),
                'X-CSRF-TOKEN': csrfToken(),
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
            body: body === undefined ? undefined : (isForm ? body : JSON.stringify(body)),
        })
    } catch (e) {
        // No response at all: the shop's connection, not the server.
        throw new ApiError('مفيش اتصال بالسيرفر. اتأكد من النت.', { status: 0, code: 'offline' })
    }

    if (response.status === 204) return null

    let payload = null
    try {
        payload = await response.json()
    } catch (e) {
        payload = null
    }

    if (response.ok) return payload

    // The shop was suspended after this page loaded. Every screen reads
    // session.readOnly, so telling the session is all it takes to correct them.
    if (payload?.code === 'business_suspended') hooks.onSuspended?.()

    throw new ApiError(
        payload?.message ?? `حصل خطأ (${response.status})`,
        {
            status: response.status,
            code: payload?.code ?? null,
            context: payload?.context ?? {},
            errors: payload?.errors ?? {},
        },
    )
}

export const api = {
    get: (path, params) => request('GET', path, { params }),
    post: (path, body) => request('POST', path, { body }),
    patch: (path, body) => request('PATCH', path, { body }),
    delete: (path) => request('DELETE', path),
}

/**
 * Cancels an invoice. Shared by the till's receipt and the dashboard's invoice
 * page: it is one call with one set of failure messages, and only the layout
 * around it differs. Resolves to the invoice as the server now sees it.
 */
export const voidInvoice = (id, reason) => api.post(`/api/invoices/${id}/void`, { reason })

/** The server's void failures in the shop's own words (its messages are English). */
export function voidMessage(error) {
    if (!(error instanceof ApiError)) return 'حصل خطأ'

    const c = error.context ?? {}

    switch (error.code) {
        case 'void_not_allowed':
            return 'إلغاء الفاتورة لمدير الفرع أو المالك بس.'
        case 'void_reason_required':
            return 'اكتب سبب الإلغاء.'
        case 'invoice_already_voided':
            return 'الفاتورة دي اتلغت قبل كده.'
        case 'invoice_has_returns':
            return 'عليها مرتجع، فمينفعش تتلغي.'
        case 'invoice_is_an_exchange':
            return 'الفاتورة دي هي البيعة الجديدة في استبدال، فمينفعش تتلغي.'
        case 'outside_void_window':
            return `عدّت مدة الإلغاء (${c.window_hours} ساعة من وقت الفاتورة).`
        case 'branch_not_allowed':
            return 'مش مسموحلك تلغي فاتورة في الفرع ده.'
    }

    if (error.fieldError('reason')) return 'اكتب سبب الإلغاء.'

    return error.message
}

/* The calls the till makes, named after what they do rather than their URLs. */
export const till = {
    me: () => api.get('/api/me'),
    login: (email, password) => api.post('/api/login', { email, password }),
    logout: () => api.post('/api/logout'),

    scan: (code, branchId) => api.get('/api/scan', { code, branch_id: branchId }),

    saveInvoice: (payload) => api.post('/api/invoices', payload),
    /** One day's invoices, newest first, 25 a page. `tz` is the browser's own. */
    invoicesOfDay: ({ date, tz, branchId, page }) =>
        api.get('/api/invoices', { date, tz, branch_id: branchId, page }),
    invoice: (id) => api.get(`/api/invoices/${id}`),

    /** Find items by name, size or colour. Rows are shaped like a scan. */
    searchVariants: (q, branchId, page = 1) =>
        api.get('/api/variants/search', { q, branch_id: branchId, page }),

    /** The till's open shift: { data: shift|null, stale }. Never carries the expected cash. */
    currentShift: (deviceId, tz) => api.get('/api/shifts/current', { device_id: deviceId, tz }),
    openShift: (payload) => api.post('/api/shifts', payload),
    /** The count. The response is where the seller first learns the expected figure. */
    closeShift: (id, payload) => api.post(`/api/shifts/${id}/close`, payload),

    lookupReceipt: (number) => api.get('/api/returns/lookup', { number }),
    saveReturn: (payload) => api.post('/api/returns', payload),
}
