<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue'
import Scanner from '../components/Scanner.vue'
import NameSearch from '../components/NameSearch.vue'
import Cart from './Cart.vue'
import { useScanReady, useWedgeScanner } from '../wedgeScanner.js'
import { hasCamera, wide } from '../layout.js'
import { session } from '../../shared/session.js'
import { till, ApiError } from '../../shared/api.js'
import { addToCart, cart, money, toMinor } from '../cart.js'
import { returnDraft } from '../returns.js'
import { shopCurrency } from '../../shared/currency.js'

const cur = shopCurrency

const emit = defineEmits(['cart', 'returns', 'invoices', 'saved', 'exchanged'])

/** Picking the replacement for a return that is already chosen. */
const isExchange = () => returnDraft.invoice !== null

const found = ref(null)
const quantity = ref(1)
const error = ref('')
const manual = ref('')
const busy = ref(false)
const toast = ref('')
const searching = ref(false)

// Counter layout only: the camera is opt-in there, and the last piece added
// stays on screen so the cashier can check it against what they just scanned.
const cameraOn = ref(false)
const lastAdded = ref(null)
const scanReady = useScanReady()

/**
 * Manual entry is the fallback for a sticker that got scuffed, and almost
 * every code the system prints is SKU-NNNNN. So the prefix is fixed and the
 * seller types digits only, on a number pad — far fewer keystrokes and no way
 * to mistype the part that never changes.
 *
 * A shop that brought its own codes, or a manufacturer barcode, needs the
 * whole field, so there is a way out.
 */
const freeCode = ref(false)

let toastTimer = null

function say(text) {
    toast.value = text
    clearTimeout(toastTimer)
    toastTimer = setTimeout(() => (toast.value = ''), 2000)
}

/** Already in the cart, so the shelf figure can account for it. */
function inCart(variantId) {
    return cart.lines.find((line) => line.variantId === variantId)?.quantity ?? 0
}

/**
 * What a resolved piece does next. The shop decides whether it goes straight
 * into the cart or stops to be checked first: a busy counter wants the first,
 * a careful one the second. The camera, the USB scanner, the manual box and
 * the name search all end here, so they cannot drift apart.
 */
function present(data) {
    const remaining = data.available - inCart(data.id)

    if (remaining <= 0) {
        error.value = `كل المتاح من ${data.product_name} موجود في السلة`
        found.value = null
        return
    }

    // At a counter the cart is on screen beside the scan, so it is the check:
    // the piece appears there with its price and can be changed or removed on
    // the spot. A sheet that must be dismissed before the next scan only stops
    // a cashier who has a scanner in one hand. On a phone the shop's setting
    // decides, as before.
    if (session.business?.quick_scan || wide.value) {
        addToCart(data, 1)
        found.value = null
        lastAdded.value = data
        say(`${data.product_name} اتضافت`)
        return
    }

    found.value = data
    quantity.value = 1
}

async function lookup(code) {
    error.value = ''
    busy.value = true

    try {
        const { data } = await till.scan(code, session.branchId)
        present(data)
    } catch (e) {
        found.value = null
        error.value = e instanceof ApiError ? e.message : 'حصل خطأ'
    } finally {
        busy.value = false
        next()
    }
}

/**
 * The camera and the manual box drop a code that arrives while another is
 * being looked up: a QR sits in frame for many frames, and one piece must not
 * be added five times.
 */
function lookupNow(code) {
    if (busy.value) return
    lookup(code)
}

/**
 * A USB scanner is the opposite: two scans a moment apart are two pieces, and
 * the second must not vanish because the first is still waiting for the
 * server. They are queued and looked up one after another, in order.
 */
const queued = []

function onWedge(code) {
    queued.push(code)
    next()
}

function next() {
    if (busy.value) return

    const code = queued.shift()
    if (code !== undefined) lookup(code)
}

// The whole screen listens, so no field has to be focused by luck. Typing in
// the manual box or the search box is left alone — see wedgeScanner.js.
useWedgeScanner(onWedge)

function pickFromSearch(variant) {
    searching.value = false
    error.value = ''
    present(variant)
}

/** How many more of this piece can go in the cart. */
function remainingOf(variant) {
    return variant.available - inCart(variant.id)
}

function submitManual() {
    const typed = manual.value.trim()
    if (!typed) return

    const code = freeCode.value
        ? typed
        : `SKU-${typed.replace(/\D/g, '')}`

    manual.value = ''
    lookupNow(code)
}

function add() {
    const variant = found.value
    if (!variant) return

    addToCart(variant, quantity.value)
    say(`${variant.product_name} اتضافت`)
    found.value = null
}

function remaining() {
    if (!found.value) return 0
    return found.value.available - inCart(found.value.id)
}

// F3 opens the name search at a counter, where a keyboard is at hand. The
// other counter shortcuts (F2 pay, F4 clear) belong to the cart and live there.
function onShortcut(event) {
    if (!wide.value || event.key !== 'F3') return

    event.preventDefault()
    searching.value = true
}

onMounted(() => window.addEventListener('keydown', onShortcut))
onBeforeUnmount(() => window.removeEventListener('keydown', onShortcut))
</script>

<template>
  <div :class="wide ? 'counter-grid' : 'screen'">
   <div class="screen" :class="{ 'scan-side': wide }">
    <div class="topbar">
      <img v-if="session.business?.brand?.logo" class="till-logo" :src="session.business.brand.logo" :alt="session.business.name">
      <h1>{{ isExchange() ? 'اختار البديل' : 'فاتورة جديدة' }}</h1>
      <button v-if="!isExchange()" class="btn" style="min-height: 40px; padding: 0 12px" @click="emit('invoices')">
        الفواتير
      </button>
      <button v-if="!isExchange()" class="btn" style="min-height: 40px; padding: 0 12px" @click="emit('returns')">
        مرتجع
      </button>
      <span class="chip">
        <span class="dot"></span>
        {{ session.device?.code }}
      </span>
    </div>

    <div v-if="isExchange() && !wide" class="msg ok">
      استبدال <span class="mono">{{ returnDraft.invoice.number }}</span> ·
      رصيد {{ money(returnDraft.refundMinor) }} {{ cur() }}
    </div>

    <!-- Ready to hear a scan, said out loud: the screen listens without any
         field being focused, so nothing else would tell the cashier. -->
    <div v-if="wide" class="scan-ready" :class="{ off: !scanReady }" role="status">
      <strong>{{ scanReady ? 'السكانر جاهز' : 'السكانر مش سامع' }}</strong>
      <span class="muted">
        {{ scanReady ? 'امسح القطعة وهتنزل السلة' : 'المؤشر جوه خانة. اضغط Esc ورجّع المسح' }}
      </span>
    </div>

    <div v-if="wide && lastAdded" class="card tight">
      <div class="muted">آخر قطعة</div>
      <div class="row">
        <strong class="grow">{{ lastAdded.product_name }}<span v-if="lastAdded.label"> · {{ lastAdded.label }}</span></strong>
        <span class="total" style="font-size: 18px">{{ lastAdded.price }} <span class="muted">{{ cur() }}</span></span>
      </div>
      <div class="muted">
        <span class="mono">{{ lastAdded.sku }}</span> · في السلة {{ inCart(lastAdded.id) }}
      </div>
    </div>

    <!-- A phone's camera is the whole screen. At a counter it is a small box,
         off until asked for, and only offered where there is a camera. -->
    <template v-if="wide">
      <button v-if="hasCamera" class="btn" type="button" @click="cameraOn = !cameraOn">
        {{ cameraOn ? 'اقفل الكاميرا' : 'شغّل الكاميرا' }}
      </button>
      <Scanner v-if="cameraOn" @code="lookupNow" />
    </template>
    <Scanner v-else @code="lookupNow" />

    <p v-if="error" class="msg bad">{{ error }}</p>

    <form @submit.prevent="submitManual" style="display: flex; flex-direction: column; gap: 6px">
      <div class="row">
        <div class="prefixed grow">
          <span v-if="!freeCode" class="prefix">SKU-</span>
          <input
            v-model="manual"
            class="input code"
            :placeholder="freeCode ? 'الكود كامل' : '10001'"
            :inputmode="freeCode ? 'text' : 'numeric'"
            :aria-label="freeCode ? 'إدخال الكود كامل' : 'رقم الكود'"
            autocomplete="off"
            autocapitalize="characters"
          >
        </div>
        <button class="btn" type="submit">بحث</button>
      </div>

      <button
        type="button"
        class="btn quiet"
        style="min-height: 34px; font-size: 13px; align-self: flex-start; padding: 0"
        @click="freeCode = !freeCode; manual = ''"
      >
        {{ freeCode ? 'رجوع لأكواد SKU' : 'كود مختلف أو باركود' }}
      </button>

      <!-- The emergency exit for a label that is gone. Small and below the
           scanner on purpose: scanning is how things are sold. -->
      <button
        type="button"
        class="btn quiet"
        style="min-height: 34px; font-size: 13px; align-self: flex-start; padding: 0"
        @click="searching = true"
      >
        الكود مش موجود؟ دوّر بالاسم<template v-if="wide"> (F3)</template>
      </button>
    </form>

    <div v-if="!wide" class="card tight row" style="justify-content: space-between; flex: none">
      <div class="grow">
        <div class="muted">
          {{ cart.isEmpty
            ? (isExchange() ? 'اعمل scan للبديل' : 'السلة فاضية')
            : `${isExchange() ? 'البديل' : 'السلة'} · ${cart.count} قطعة` }}
        </div>
        <div class="total">{{ money(cart.totalMinor) }} <span class="muted">{{ cur() }}</span></div>
      </div>
      <button class="btn primary" :disabled="cart.isEmpty" @click="emit('cart')">
        {{ isExchange() ? 'كمّل الاستبدال' : 'عرض السلة' }}
      </button>
    </div>

    <!-- what the scan found -->
    <div v-if="found" class="scrim" @click.self="found = null">
      <div class="sheet" role="dialog" aria-modal="true" aria-labelledby="found-name">
        <span class="grab" aria-hidden="true"></span>

        <div class="row wrap" style="gap: 10px">
          <span class="chip">تم قراءة الكود</span>
          <span class="mono muted">{{ found.sku }}</span>
        </div>

        <h2 id="found-name" style="margin: 4px 0 0; font-size: 23px; font-weight: 700; line-height: 1.3">
          {{ found.product_name }}
        </h2>

        <div v-if="found.label" class="row wrap">
          <span class="tag">{{ found.label }}</span>
        </div>

        <div class="row wrap" style="align-items: baseline; gap: 12px; margin-top: 4px">
          <span class="price-lg">{{ found.price }}</span>
          <span class="muted">{{ cur() }}</span>
          <span class="muted" style="margin-right: auto">
            {{ remaining() === 1 ? 'آخر قطعة في الفرع' : `متاح ${remaining()}` }}
          </span>
        </div>

        <div class="row" style="margin-top: 8px">
          <span class="grow" style="font-weight: 600">الكمية</span>
          <div class="stepper">
            <button
              class="step"
              aria-label="زود الكمية"
              :disabled="quantity >= remaining()"
              @click="quantity++"
            >
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                   stroke-width="2.2" stroke-linecap="round" aria-hidden="true">
                <path d="M12 5v14M5 12h14" />
              </svg>
            </button>
            <span class="qty">{{ quantity }}</span>
            <button class="step" aria-label="قلل الكمية" :disabled="quantity <= 1" @click="quantity--">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                   stroke-width="2.2" stroke-linecap="round" aria-hidden="true">
                <path d="M5 12h14" />
              </svg>
            </button>
          </div>
        </div>

        <button class="btn primary big full" style="margin-top: 6px" @click="add">
          أضف للسلة · {{ money(toMinor(found.price) * quantity) }} {{ cur() }}
        </button>
        <button class="btn quiet full" @click="found = null">إلغاء</button>
      </div>
    </div>

    <NameSearch
      v-if="searching"
      :remaining-of="remainingOf"
      @pick="pickFromSearch"
      @close="searching = false"
    />

    <div v-if="toast" class="toast" role="status">{{ toast }}</div>
   </div>

    <!-- The cart, beside the scan and never behind another screen, so the
         total is in view for every scan. The same component a phone shows
         full-screen: one cart, one way to save it. -->
    <section v-if="wide" class="cart-side" aria-label="السلة">
      <Cart embedded @saved="emit('saved', $event)" @exchanged="emit('exchanged', $event)" />
    </section>
  </div>
</template>
