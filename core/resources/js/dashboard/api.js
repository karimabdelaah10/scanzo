import { api } from '../shared/api.js'

/** The calls the dashboard makes, named for what they do. */
export const dash = {
    categories: () => api.get('/api/categories'),
    createCategory: (name, parentId) => api.post('/api/categories', { name, parent_id: parentId || null }),

    updateCategory: (id, body) => api.patch(`/api/categories/${id}`, body),
    deleteCategory: (id) => api.delete(`/api/categories/${id}`),

    // Sales over a date range, added up by the server.
    salesReport: (params) => api.get('/api/reports/sales', params),

    products: (params) => api.get('/api/products', params),
    product: (id, branchId) => api.get(`/api/products/${id}`, { branch_id: branchId }),
    createProduct: (payload) => api.post('/api/products', payload),
    updateProduct: (id, body) => api.patch(`/api/products/${id}`, body),

    // Bulk catalogue load. The template is a plain download link (see Import.vue).
    importCatalogue: (file, branchId) => {
        const form = new FormData()
        form.append('file', file)
        if (branchId) form.append('branch_id', branchId)

        return api.post('/api/catalogue/import', form)
    },

    updateVariant: (id, body) => api.patch(`/api/variants/${id}`, body),
    deleteVariant: (id) => api.delete(`/api/variants/${id}`),

    // The shop itself. Writes are the owner's alone; the server enforces it.
    branches: () => api.get('/api/branches'),
    createBranch: (body) => api.post('/api/branches', body),
    updateBranch: (id, body) => api.patch(`/api/branches/${id}`, body),
    deleteBranch: (id) => api.delete(`/api/branches/${id}`),

    users: () => api.get('/api/users'),
    createUser: (body) => api.post('/api/users', body),
    updateUser: (id, body) => api.patch(`/api/users/${id}`, body),
    deleteUser: (id) => api.delete(`/api/users/${id}`),

    devices: (params) => api.get('/api/devices', params),
    createDevice: (body) => api.post('/api/devices', body),
    updateDevice: (id, body) => api.patch(`/api/devices/${id}`, body),
    deleteDevice: (id) => api.delete(`/api/devices/${id}`),

    settings: () => api.get('/api/settings'),
    updateSettings: (body) => api.patch('/api/settings', body),
    // What two colours would become (derived foregrounds, adjusted or not), unsaved.
    previewBrand: (params) => api.get('/api/brand/preview', params),
    uploadLogo: (file) => {
        const body = new FormData()
        body.append('logo', file)
        return api.post('/api/settings/logo', body)
    },
    removeLogo: () => api.delete('/api/settings/logo'),

    // The day's list and its totals come from the same filters on the server.
    invoices: (params) => api.get('/api/invoices', params),
    invoiceSummary: (params) => api.get('/api/invoices/summary', params),
    invoice: (id) => api.get(`/api/invoices/${id}`),

    // Till shifts with their drawer differences. The filters are the server's.
    shifts: (params) => api.get('/api/shifts', params),

    stock: (params) => api.get('/api/stock', params),
    movements: (params) => api.get('/api/stock/movements', params),
    receive: (payload) => api.post('/api/stock/receive', payload),
    adjust: (payload) => api.post('/api/stock/adjust', payload),
    transfers: (params) => api.get('/api/stock/transfers', params),
    transfer: (payload) => api.post('/api/stock/transfers', payload),
    receiveTransfer: (id, items) => api.post(`/api/stock/transfers/${id}/receive`, { items }),
    settleTransfer: (id, payload) => api.post(`/api/stock/transfers/${id}/settle`, payload),
    cancelTransfer: (id) => api.post(`/api/stock/transfers/${id}/cancel`),
}

/**
 * Every row the stock endpoint has for a filter, page by page.
 *
 * The server filters `low` and `negative` AFTER it has cut a page of 50
 * variants, so one page can come back nearly empty while the next one holds the
 * matches. Walking the pages is the only way to get a true answer from it.
 */
export async function allStock(params, { maxPages = 40 } = {}) {
    const rows = []
    let page = 1
    let last = 1

    do {
        const res = await dash.stock({ ...params, page })
        rows.push(...res.data)
        last = res.meta.last_page
        page += 1
    } while (page <= last && page <= maxPages)

    return { rows, truncated: last > maxPages }
}

/**
 * A typed or scanned code → stock rows for the branch. Goes through /api/stock
 * rather than /api/scan because scan refuses items that are off sale, and a
 * delivery of one is still a delivery.
 */
export async function findByCode(code, branchId) {
    const text = code.trim()
    const { data } = await dash.stock({ branch_id: branchId, search: text })
    const exact = data.filter((r) => r.sku.toLowerCase() === text.toLowerCase())

    return exact.length === 1 ? exact : data
}
