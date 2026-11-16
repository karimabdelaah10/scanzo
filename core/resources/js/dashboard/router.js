import { ref, computed } from 'vue'

/**
 * A router on the hash, because a refresh must land on the same screen and the
 * server has exactly one dashboard URL. Patterns may carry :params, and the
 * query string after "?" is parsed too.
 */
const routes = [
    ['products.new', '/products/new'],
    ['products', '/products'],
    ['import', '/import'],
    ['stock.receive', '/stock/receive'],
    ['stock.count', '/stock/count'],
    ['stock.transfer', '/stock/transfer'],
    ['stock.transfers', '/stock/transfers'],
    ['stock.movements', '/stock/movements/:id'],
    ['stock', '/stock'],
    ['invoices.show', '/invoices/:id'],
    ['invoices', '/invoices'],
    ['labels', '/labels'],
    ['categories', '/categories'],
    ['shifts', '/shifts'],
    ['reports', '/reports'],
    ['team', '/team'],
    ['branches', '/branches'],
    ['devices', '/devices'],
    ['settings', '/settings'],
]

const raw = ref(location.hash)
window.addEventListener('hashchange', () => { raw.value = location.hash })

export const route = computed(() => {
    const [path, qs = ''] = (raw.value.replace(/^#/, '') || '/products').split('?')
    const parts = path.split('/').filter(Boolean)

    for (const [name, pattern] of routes) {
        const want = pattern.split('/').filter(Boolean)
        if (want.length !== parts.length) continue

        const params = {}
        const ok = want.every((seg, i) => seg.startsWith(':')
            ? (params[seg.slice(1)] = decodeURIComponent(parts[i]), true)
            : seg === parts[i])

        if (ok) return { name, params, query: Object.fromEntries(new URLSearchParams(qs)) }
    }

    return { name: 'products', params: {}, query: {} }
})

/** go('/stock/movements/abc', { sku: 'SKU-1' }) */
export function go(path, query) {
    const qs = new URLSearchParams(query ?? {}).toString()
    location.hash = qs ? `${path}?${qs}` : path
}

/** The href for a link, so the browser handles open-in-new-tab and back. */
export const href = (path, query) => {
    const qs = new URLSearchParams(query ?? {}).toString()
    return qs ? `#${path}?${qs}` : `#${path}`
}
