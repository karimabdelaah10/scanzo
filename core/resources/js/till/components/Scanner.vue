<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { Html5Qrcode } from 'html5-qrcode'

/**
 * The camera.
 *
 * getUserMedia only works over HTTPS or on localhost, so a till served from
 * http://<some-ip> gets no camera at all — the message below says so plainly
 * rather than looking broken, because that is the one failure a seller cannot
 * work around on their own.
 *
 * The same code is emitted at most once per second: a QR stays in frame for
 * many frames, and without that a single scan would add the item five times.
 */

const emit = defineEmits(['code'])

const state = ref('starting')
const message = ref('')
const torchOn = ref(false)

let scanner = null
let lastCode = null
let lastAt = 0
const REPEAT_MS = 1200

const secureContext = window.isSecureContext

function handle(text) {
    const code = String(text ?? '').trim()
    if (!code) return

    const now = Date.now()
    if (code === lastCode && now - lastAt < REPEAT_MS) return

    lastCode = code
    lastAt = now

    if (navigator.vibrate) navigator.vibrate(40)

    emit('code', code)
}

async function start() {
    if (!secureContext) {
        state.value = 'insecure'
        return
    }

    try {
        scanner = new Html5Qrcode('scanzo-camera', { verbose: false })

        await scanner.start(
            { facingMode: 'environment' },
            // A tall box: labels are scanned from directly above the hang tag.
            { fps: 10, qrbox: { width: 220, height: 220 } },
            handle,
            () => {},
        )

        state.value = 'running'
    } catch (e) {
        state.value = 'denied'
        message.value = String(e?.message ?? e)
    }
}

async function toggleTorch() {
    try {
        await scanner?.applyVideoConstraints({
            advanced: [{ torch: !torchOn.value }],
        })
        torchOn.value = !torchOn.value
    } catch (e) {
        // Most phones refuse it; the button simply does nothing then.
    }
}

onMounted(start)

onBeforeUnmount(async () => {
    try {
        if (scanner?.isScanning) await scanner.stop()
        scanner?.clear()
    } catch (e) {
        // Already gone.
    }
})
</script>

<template>
  <div class="camera">
    <div id="scanzo-camera" style="width: 100%; height: 100%"></div>

    <div v-if="state === 'running'" class="reticle" aria-hidden="true">
      <i></i><i></i><i></i><i></i><b></b>
    </div>

    <button
      v-if="state === 'running'"
      class="icon-btn"
      style="position: absolute; top: 10px; left: 10px; background: rgba(255,255,255,.16); color: #fff"
      :aria-label="torchOn ? 'إطفاء الكشاف' : 'تشغيل الكشاف'"
      @click="toggleTorch"
    >
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor"
           stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path d="M13 2L4 14h7l-1 8 9-12h-7l1-8z" />
      </svg>
    </button>

    <p v-if="state === 'starting'" class="notice">جاري تشغيل الكاميرا…</p>

    <div v-else-if="state === 'insecure'" class="notice">
      <strong>الكاميرا محتاجة HTTPS</strong>
      <p class="muted" style="color: #9ca3af">
        المتصفح مش بيسمح بالكاميرا على عنوان غير مؤمّن. افتح السيستم من
        <span class="mono">https</span> أو من <span class="mono">localhost</span>،
        أو استخدم إدخال الكود يدوي.
      </p>
    </div>

    <div v-else-if="state === 'denied'" class="notice">
      <strong>مفيش وصول للكاميرا</strong>
      <p class="muted" style="color: #9ca3af">
        اسمح للكاميرا من إعدادات المتصفح وافتح الصفحة تاني، أو استخدم إدخال
        الكود يدوي.
      </p>
      <p v-if="message" class="muted" style="color: #6b7280; font-size: 11px">{{ message }}</p>
    </div>
  </div>
</template>
