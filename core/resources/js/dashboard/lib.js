import { reactive } from 'vue'
import { ApiError } from '../shared/api.js'
import { session, startSession, refreshWorkspace } from '../shared/session.js'

/* ---------- money: whole piastres on this side, decimal strings on the wire */

const ARABIC_DIGITS = '٠١٢٣٤٥٦٧٨٩'

/** Shop owners type with an Arabic keyboard: ٤٥٠٫٥ must read as 450.5. */
export function normalizeNumber(text) {
    return String(text ?? '')
        .replace(/[٠-٩]/g, (d) => ARABIC_DIGITS.indexOf(d))
        .replace(/[٫,]/g, '.')
        .trim()
}

/** "450.50" | "٤٥٠" | 450 → 45050, or null when it is not a number. */
export function toMinor(input) {
    const text = normalizeNumber(input)
    if (text === '' || !/^\d*\.?\d+$|^\d+\.$/.test(text)) return null
    return Math.round(Number(text) * 100)
}

export const toDecimal = (minor) => (minor / 100).toFixed(2)

export function money(value) {
    const minor = typeof value === 'number' ? value : toMinor(value) ?? 0
    return (minor / 100).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

/** A whole-number field ("5", "٥"), or null. */
export function toInt(input) {
    const text = normalizeNumber(input)
    return /^-?\d+$/.test(text) ? Number(text) : null
}

export function dateTime(iso) {
    return iso
        ? new Date(iso).toLocaleString('ar-EG', { dateStyle: 'medium', timeStyle: 'short' })
        : ''
}

/* ---------- which branch the dashboard is looking at ---------- */

const BRANCH_KEY = 'scanzo.dashboard.branch'

/**
 * Separate from the till's remembered branch on purpose: an owner checking
 * another branch's stock must not move the till they sell on.
 */
export const view = reactive({ branchId: null })

export function pickInitialBranch() {
    let stored = null
    try { stored = localStorage.getItem(BRANCH_KEY) } catch (e) { /* a convenience only */ }

    view.branchId = session.branches.some((b) => b.id === stored)
        ? stored
        : (session.branches[0]?.id ?? null)
}

export function chooseViewBranch(id) {
    view.branchId = id
    try { localStorage.setItem(BRANCH_KEY, id) } catch (e) { /* ignore */ }
}

export const branchName = (id) => session.branches.find((b) => b.id === id)?.name ?? ''

/* ---------- errors ---------- */

const WHAT = { branches: 'فروع', users: 'مستخدمين', devices: 'أجهزة كاشير' }

/** What to tell a shop owner; the server's own message is English. */
export function explain(error) {
    if (!(error instanceof ApiError)) return 'حصل خطأ غير متوقع'

    const c = error.context ?? {}

    switch (error.code) {
        case 'offline':
            return error.message
        case 'plan_limit_reached':
            return `باقتك بتسمح بـ ${c.limit} ${WHAT[c.what] ?? c.what} بس. كلّم الدعم لو عايز تزوّد.`
        case 'duplicate_sku':
            return `الكود ${c.sku} مستخدم في قطعة تانية. جرّب تحفظ تاني.`
        case 'variant_still_in_stock':
            return `${c.sku} لسه فيه ${c.on_hand} قطعة على الرف، فمينفعش يتمسح. أوقفه عن البيع بدل المسح.`
        case 'catalogue_not_allowed':
            return 'مش مسموحلك تعدّل في الكتالوج.'
        case 'stock_not_allowed':
            return 'مش مسموحلك تغيّر المخزون.'
        case 'transfer_same_branch':
            return 'اختار فرع تاني للتحويل: المصدر والهدف نفس الفرع.'
        case 'transfer_from_other_branch':
            return 'تقدر تحوّل من فرعك بس. السحب من فرع تاني للمالك.'
        case 'invalid_transfer_quantity':
            return 'كمية التحويل لازم تكون 1 على الأقل.'
        case 'transfer_not_in_transit':
            return 'التحويل ده مبقاش في الطريق: اتأكد أو اتلغى قبل كده. حدّث الشاشة.'
        case 'transfer_receive_not_allowed':
            return 'الاستلام لمدير الفرع المستلم أو المالك. الفرع اللي بعت مينفعش يأكد لنفسه.'
        case 'transfer_cancel_not_allowed':
            return 'الإلغاء لمدير الفرع اللي بعت أو المالك.'
        case 'transfer_received_over_sent':
            return `${c.sku}: استلمت ${c.received} والمبعوت ${c.sent} بس.`
        case 'transfer_receipt_lines_mismatch':
            return 'لازم تكتب الكمية المستلمة لكل صنف في التحويل.'
        case 'branch_has_transfers':
            return 'الفرع ده عليه تحويلات مسجلة، فمينفعش يتمسح. أوقفه بدل المسح.'
        case 'out_of_stock':
            return `${c.sku}: المتاح ${c.available} بس، والمطلوب ${c.requested}.`
        case 'branch_not_allowed':
            return 'مش مسموحلك تشتغل على الفرع ده.'
        case 'variant_not_found':
            return 'القطعة دي مش موجودة.'
        case 'nothing_to_print':
            return 'مفيش استيكرات تتطبع: مفيش حاجة مختارة أو مفيش رصيد.'
        case 'too_many_labels':
            return `${c.requested} استيكر كتير. اطبع ${c.limit} على الأكتر في المرة.`
        case 'product_needs_a_variant':
            return 'المنتج لازم يكون له مقاس أو لون واحد على الأقل.'
        case 'business_not_allowed':
            return 'التعديل ده للمالك بس.'
        case 'account_inactive':
            return 'حسابك اتوقف. كلّم صاحب المحل.'
        case 'last_owner':
            return 'مينفعش المحل يفضل من غير مالك.'
        case 'own_account':
            return 'مينفعش تعمل ده في حسابك إنت.'
        case 'user_has_history':
            return `${c.name} موجود على فواتير، فمينفعش يتمسح. أوقف الحساب بدل المسح.`
        case 'last_branch':
            return 'المحل لازم يفضل فيه فرع واحد على الأقل.'
        case 'branch_has_history':
            return `${c.name} عليه مبيعات، فمينفعش يتمسح. أوقفه بدل المسح.`
        case 'branch_has_stock_history':
            return `${c.name} فيه سجل مخزون (استلام أو جرد أو حركة). لو اتمسح السجل ده بيضيع، ومفيش طريقة نرجّع بيها الكميات. أوقف الفرع بدل المسح.`
        case 'device_has_history':
            return `الجهاز ${c.code} كتب فواتير، فمينفعش يتمسح. أوقفه بدل المسح.`
        case 'category_has_products':
            return c.archived > 0
                ? `${c.name} فيه ${c.products} منتج، منهم ${c.archived} مؤرشف. المؤرشف مش ظاهر على الشاشة بس لسه تابع للتصنيف ده. رجّعهم وانقلهم لتصنيف تاني الأول وبعدين امسح.`
                : `${c.name} فيه ${c.products} منتج. انقلهم لتصنيف تاني الأول وبعدين امسح.`
        case 'import_library_missing':
            return 'قراءة ملفات Excel (xlsx) محتاجة مكتبة مش متسطبة على السيرفر. احفظ الملف كـ CSV UTF-8 وارفعه، أو اطلب من المسؤول يسطّب المكتبة.'
        case 'import_unsupported_file':
            return 'ارفع ملف xlsx أو csv. ملفات xls القديمة مش مدعومة: افتحها في Excel واحفظها كـ xlsx.'
        case 'import_unreadable':
            return 'مقدرتش أقرا الملف. يمكن تالف أو عليه كلمة سر.'
        case 'import_empty':
            return 'الملف مفيهوش منتجات تحت سطر العناوين.'
        case 'import_missing_columns':
            return `سطر العناوين ناقصه: ${(c.missing ?? []).join('، ')}. نزّل القالب واستعمل عناوينه.`
        case 'import_duplicate_column':
            return `العمود «${c.column}» مكتوب مرتين في سطر العناوين.`
        case 'import_too_large':
            return `الملف أكبر من ${c.limit} سطر. قسّمه لأجزاء وارفعها واحد ورا التاني.`
        case 'import_in_progress':
            return 'فيه استيراد تاني شغال دلوقتي للمحل. استنى لما يخلص.'
        case 'import_rejected':
            return `في الملف ${c.error_count} خطأ، ومفيش حاجة اتسجّلت.`
        case 'category_has_children':
            return `${c.name} فيه ${c.children} تصنيف فرعي. انقلهم أو امسحهم الأول.`
    }

    if (error.isValidation) {
        const first = Object.values(error.errors).flat()[0]
        return first ? `${error.message} — ${first}` : error.message
    }

    return error.message
}

/** Message for a failure; a lapsed session sends the user back to sign in. */
export function fail(error) {
    // The server has already ended this session; going back to sign-in is all
    // that is left, but say why, because the form alone would look like a bug.
    if (error instanceof ApiError && error.code === 'account_inactive') {
        startSession()
        say(explain(error))
        return explain(error)
    }

    if (error instanceof ApiError && error.isUnauthenticated) {
        startSession()
        return 'انتهت الجلسة. سجّل دخولك تاني.'
    }
    return explain(error)
}

/**
 * The message for one field of a rejected form, or ''. The server's own text is
 * English, so the two it sends for a taken email or code are said in Arabic.
 */
export function fieldMessage(error, field) {
    if (!(error instanceof ApiError)) return ''

    const text = error.fieldError(field)
    if (!text) return ''

    return /already|taken/i.test(text) ? 'مستخدم قبل كده' : text
}

/** After the owner changes branches or tills, the pickers must follow. */
export async function refreshAfterAdmin() {
    try {
        await refreshWorkspace()
        if (!session.branches.some((b) => b.id === view.branchId)) pickInitialBranch()
    } catch (e) { /* the screen already shows what was saved */ }
}

/* ---------- a toast, for "saved" ---------- */

export const toast = reactive({ text: '' })
let timer = null

export function say(text) {
    toast.text = text
    clearTimeout(timer)
    timer = setTimeout(() => { toast.text = '' }, 2200)
}

/* ---------- labels ---------- */

export const TEMPLATES = [
    { value: 'roll-40x30', label: 'رول 40×30 مم', barcode: false },
    { value: 'roll-50x30', label: 'رول 50×30 مم', barcode: true },
    { value: 'a4-24', label: 'ورقة A4 — 24 استيكر', barcode: true },
]

/** /labels/print?... (or /labels/pdf?...) exactly as LabelController reads it. */
export function labelsUrl({ productId, variantIds, branchId, copies = 'stock', count, template, showPrice, showBarcode, kind = 'print', download = false }) {
    const q = new URLSearchParams()

    if (productId) q.set('product_id', productId)
    else (variantIds ?? []).forEach((id) => q.append('variant_ids[]', id))

    if (branchId) q.set('branch_id', branchId)
    q.set('copies', copies)
    if (copies === 'fixed') q.set('count', String(count ?? 1))
    if (template) q.set('template', template)
    if (showPrice !== undefined) q.set('show_price', showPrice ? '1' : '0')
    if (showBarcode !== undefined) q.set('show_barcode', showBarcode ? '1' : '0')

    if (download) q.set('download', '1')

    return `/labels/${kind}?${q}`
}

/** Debounce for search boxes. */
export function debounced(fn, ms = 300) {
    let t
    return (...args) => { clearTimeout(t); t = setTimeout(() => fn(...args), ms) }
}
