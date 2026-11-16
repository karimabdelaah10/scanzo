import { onBeforeUnmount, onMounted, ref } from 'vue'

/**
 * A USB barcode scanner, which the computer sees as a keyboard that types the
 * whole code in a few milliseconds and presses Enter.
 *
 * The only thing that tells it from a person is speed. So characters are
 * collected while each one follows the last within MAX_GAP_MS, and an Enter
 * that arrives on such a run is a scan. Anything slower — a person typing,
 * even fast — never qualifies, and nothing is ever swallowed unless it was
 * recognised as a scan: ordinary keys are left alone.
 */

/** Scanners type a character every 5–30 ms; a person is never under ~80. */
export const MAX_GAP_MS = 50

/** Shorter than this is not a code, whatever the speed. */
export const MIN_LENGTH = 4

/** Held down while a scanner types capitals; they say nothing either way. */
const IGNORED_KEYS = new Set(['Shift', 'CapsLock', 'AltGraph'])

/**
 * The character a key stands for, whatever the keyboard layout.
 *
 * A scanner sends key positions, so on a till left on the Arabic layout "SKU-"
 * would arrive as Arabic letters. Letters, digits and the hyphen are therefore
 * read from the physical key (`code`), not from what the layout turned it into.
 */
export function charOf(event) {
    const { key, code } = event

    if (key.length === 1 && key.charCodeAt(0) < 128) return key

    if (/^Key[A-Z]$/.test(code)) return event.shiftKey ? code[3] : code[3].toLowerCase()
    if (/^Digit\d$/.test(code)) return code[5]
    if (/^Numpad\d$/.test(code)) return code[6]
    if (code === 'Minus' || code === 'NumpadSubtract') return '-'

    return key.length === 1 ? key : null
}

/**
 * The state machine, with no DOM in it so it can be reasoned about (and tested)
 * on its own. `press` returns the code when this key completes a scan.
 */
export function createDetector({ maxGap = MAX_GAP_MS, minLength = MIN_LENGTH } = {}) {
    let buffer = ''
    let last = 0

    return {
        press(char, at) {
            if (char === 'Enter') {
                const scanned = buffer.length >= minLength && at - last <= maxGap
                const code = buffer
                buffer = ''

                return scanned ? code : null
            }

            if (char === null) {
                buffer = ''
                return null
            }

            // Too slow to be the same burst: whatever was collected was a person.
            if (buffer && at - last > maxGap) buffer = ''

            buffer += char
            last = at

            return null
        },

        reset() {
            buffer = ''
        },
    }
}

const isEditable = (el) =>
    el instanceof Element && el.closest('input, textarea, select, [contenteditable]:not([contenteditable="false"])') !== null

/**
 * Listens on the whole screen while the calling component is mounted, and
 * calls `onCode(code)` for each scan.
 *
 * Someone typing in a field is left alone entirely: the keys go to the field,
 * and a scan that lands there is the field's to handle (the manual box submits
 * on Enter, as before). The listener only acts on a screen that is not in the
 * middle of typing.
 */
export function useWedgeScanner(onCode) {
    const detector = createDetector()

    function onKeydown(event) {
        if (event.isComposing || event.repeat) return

        if (isEditable(event.target)) {
            detector.reset()
            return
        }

        // Shortcuts are not scanner input.
        if (event.ctrlKey || event.metaKey || event.altKey) {
            detector.reset()
            return
        }

        if (IGNORED_KEYS.has(event.key)) return

        const char = event.key === 'Enter' ? 'Enter' : (event.key.length === 1 ? charOf(event) : null)
        const code = detector.press(char, event.timeStamp)

        if (code !== null) {
            // The scanner's Enter would otherwise "click" whatever button has
            // focus — the one on screen after the last tap.
            event.preventDefault()
            onCode(code)
        }
    }

    onMounted(() => window.addEventListener('keydown', onKeydown))
    onBeforeUnmount(() => window.removeEventListener('keydown', onKeydown))
}

/**
 * Whether a scan would be heard right now. It is not while a field has focus
 * (the listener leaves typing alone), and on a counter that is the one way a
 * cashier loses scans without knowing: a discount typed, then the next piece
 * scanned into the discount box. The screen shows this so it can say so, and
 * Escape gives the focus back.
 */
export function useScanReady() {
    const ready = ref(true)

    const update = () => {
        ready.value = !isEditable(document.activeElement)
    }
    // During focusout the new element is not active yet.
    const afterFocusMoves = () => setTimeout(update, 0)

    function onEscape(event) {
        if (event.key === 'Escape' && isEditable(document.activeElement)) {
            document.activeElement.blur()
            update()
        }
    }

    onMounted(() => {
        update()
        document.addEventListener('focusin', update)
        document.addEventListener('focusout', afterFocusMoves)
        window.addEventListener('keydown', onEscape)
    })

    onBeforeUnmount(() => {
        document.removeEventListener('focusin', update)
        document.removeEventListener('focusout', afterFocusMoves)
        window.removeEventListener('keydown', onEscape)
    })

    return ready
}
