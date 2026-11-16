import { ref } from 'vue'

/**
 * Which layout the till is drawn in. Layout only: nothing in here changes what
 * the till does, and the state it arranges stays in cart.js and shared/.
 *
 * The counter layout puts the scan side and the cart side by side. It needs a
 * scan column of about 340px and a cart that can still show a line's name, its
 * discount row and its stepper without wrapping, about 460px, plus the gap and
 * margins. That is just under 900px, so 1024px is the first common width where
 * both sit comfortably and the total keeps its room. Below it (every phone, a
 * tablet held upright) the screen-at-a-time layout is used.
 */
export const COUNTER_MIN_WIDTH = 1024

const query = window.matchMedia(`(min-width: ${COUNTER_MIN_WIDTH}px)`)

/** True while the window is wide enough for scan and cart on one screen. */
export const wide = ref(query.matches)

query.addEventListener('change', (event) => {
    wide.value = event.matches
})

/**
 * Whether this device has a camera at all. Asked without opening it, so no
 * permission prompt appears just because the screen was drawn. Where the browser
 * cannot say (an insecure page hides the media API), the answer is no and the
 * camera button simply is not offered.
 */
export const hasCamera = ref(false)

if (navigator.mediaDevices?.enumerateDevices) {
    navigator.mediaDevices
        .enumerateDevices()
        .then((devices) => {
            hasCamera.value = devices.some((device) => device.kind === 'videoinput')
        })
        .catch(() => {})
}
