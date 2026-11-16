import { reactive } from 'vue'
import { till, ApiError } from '../shared/api.js'
import { session } from '../shared/session.js'

/**
 * The till's drawer shift: state and I/O only. The views arrange it.
 *
 * Selling is NOT blocked without a shift — a counter that cannot sell because
 * a form was skipped is worse than a drawer nobody counted — so this is shown
 * as a nudge, never as a gate.
 *
 * The expected cash is never held here while the shift is open: the server
 * does not send it, and the screen has nothing to show before the count.
 */

const tz = Intl.DateTimeFormat().resolvedOptions().timeZone

export const shift = reactive({
    loaded: false,
    // The open shift on this till, or null.
    current: null,
    // Open, but from an earlier shop day: never counted against, only replaced.
    stale: false,
    // Yesterday's shift that opening this one closed without a count.
    autoClosed: null,
    // The start-of-work offer was shown and put away. Asked once per sign-in,
    // never per scan; the banner on the sell screen is what stays.
    promptDismissed: false,
})

export async function loadShift() {
    if (!session.deviceId) return

    try {
        const res = await till.currentShift(session.deviceId, tz)
        shift.current = res.data
        shift.stale = res.stale === true
    } catch (e) {
        // Not being able to ask must not stop the till selling.
        shift.current = null
        shift.stale = false
    } finally {
        shift.loaded = true
    }
}

export function clearShift() {
    shift.loaded = false
    shift.current = null
    shift.stale = false
    shift.autoClosed = null
    shift.promptDismissed = false
}

/**
 * A new id per attempt at a given form, kept by the caller, so pressing the
 * button twice or retrying after a dropped connection cannot open two shifts.
 * The device's own clock goes with it: that is the shift's time, not the
 * moment the server heard.
 */
export const newShiftId = () => globalThis.crypto?.randomUUID?.() ?? undefined

export async function openShift({ id, openingFloat }) {
    const res = await till.openShift({
        ...(id ? { id } : {}),
        device_id: session.deviceId,
        opening_float: openingFloat,
        opened_at: new Date().toISOString(),
        tz,
    })

    shift.current = res.data
    shift.stale = false
    shift.autoClosed = res.auto_closed ?? null

    return res
}

export async function closeShift({ counted, note }) {
    const res = await till.closeShift(shift.current.id, {
        counted_cash: counted,
        ...(note ? { note } : {}),
        closed_at: new Date().toISOString(),
    })

    shift.current = null
    shift.stale = false
    // Closing it on purpose is not "work is starting": no offer straight after.
    shift.promptDismissed = true

    return res.data
}

/** The server's shift failures in the shop's own words (its messages are English). */
export function shiftMessage(error) {
    if (!(error instanceof ApiError)) return 'حصل خطأ'

    switch (error.code) {
        case 'shift_already_open':
            return `الكاشير ده عليه وردية مفتوحة${error.context?.opened_by ? ` (${error.context.opened_by})` : ''}. لازم تتقفل الأول.`
        case 'shift_already_closed':
            return 'الوردية دي اتقفلت قبل كده.'
        case 'shift_not_yours':
            return 'ده مش ورديتك. مدير الفرع هو اللي يقفلها.'
        case 'device_inactive':
            return 'الكاشير ده متوقف.'
        case 'branch_not_allowed':
            return 'مش مسموحلك تشتغل في الفرع ده.'
        case 'shift_closed_before_opened':
            return 'وقت القفل قبل وقت الفتح. اتأكد من ساعة الجهاز.'
    }

    if (error.fieldError('opening_float') || error.fieldError('counted_cash')) {
        return 'اكتب المبلغ بالأرقام (صفر أو أكتر).'
    }

    return error.message
}
