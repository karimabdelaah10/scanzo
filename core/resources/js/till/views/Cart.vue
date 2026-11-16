<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { session } from '../../shared/session.js'
import { till, ApiError } from '../../shared/api.js'
import {
    cart, cartPayload, clearCart, lineDiscountMinor, lineDiscountOverLimit, lineGrossMinor, lineNetMinor,
    money, removeLine, setQuantity,
} from '../cart.js'
import { clearReturn, returnDraft, returnPayload } from '../returns.js'
import { shopCurrency } from '../../shared/currency.js'

const cur = shopCurrency

// Only offered to someone the server will accept a discount from; it is the
// server that decides, this just avoids showing a control that always fails.
const canDiscount = () => session.business?.can_discount === true
const maxPercent = () => session.business?.discount_max_percent ?? 100

/** '' when fine, otherwise what is wrong with the invoice discount. */
const invoiceDiscountProblem = () => {
    if (cart.discountTooLarge) return 'الخصم أكبر من الفاتورة'
    if (cart.discountOverLimit(maxPercent())) return `أقصى خصم ${maxPercent()}% على الفاتورة (خصم الأصناف + خصم الفاتورة معاً)`

    return ''
}

/** '' when fine, otherwise what is wrong with this line's discount. */
function discountProblem(line) {
    if (lineDiscountMinor(line) > lineGrossMinor(line)) return 'الخصم أكبر من سعر السطر'
    if (lineDiscountOverLimit(line, maxPercent())) return `أقصى خصم ${maxPercent()}% على السطر`

    return ''
}

const hasDiscountProblem = () => cart.lines.some((line) => discountProblem(line) !== '')

const emit = defineEmits(['back', 'saved', 'exchanged'])

// `embedded` is the counter layout: the cart sits beside the scan instead of
// being a screen of its own, so it has no back button and no "scan another".
const props = defineProps({
    embedded: { type: Boolean, default: false },
})

const error = ref('')
const errorCode = ref('')
const shortVariantId = ref(null)
const busy = ref(false)

/** An exchange is in progress when a return is waiting for its replacement. */
const isExchange = () => returnDraft.invoice !== null

async function save() {
    error.value = ''
    errorCode.value = ''
    shortVariantId.value = null
    busy.value = true

    try {
        // The exchange goes in one call, so the goods coming back and the ones
        // going out are saved together or not at all — a customer must never
        // be refunded for a swap whose replacement then failed.
        if (isExchange()) {
            const response = await till.saveReturn({
                device_id: session.deviceId,
                invoice_id: returnDraft.invoice.id,
                items: returnPayload(),
                exchange_items: cartPayload(),
                reason: returnDraft.reason || null,
                override_window: returnDraft.overrideWindow,
                payment_method: cart.paymentMethod,
            })

            clearCart()
            clearReturn()
            emit('exchanged', response)
            return
        }

        const { data } = await till.saveInvoice({
            device_id: session.deviceId,
            items: cartPayload(),
            discount_amount: cart.discountMinor / 100,
            payment_method: cart.paymentMethod,
        })

        clearCart()
        emit('saved', data)
    } catch (e) {
        if (!(e instanceof ApiError)) {
            error.value = 'حصل خطأ غير متوقع'
            return
        }

        error.value = e.message
        errorCode.value = e.code ?? ''

        // The server names the item it refused, so the line is marked instead
        // of the whole sale just failing.
        if (e.code === 'out_of_stock') {
            const line = cart.lines.find((l) => l.sku === e.context.sku)

            if (line) {
                shortVariantId.value = line.variantId
                line.available = e.context.available
            }
        }
    } finally {
        busy.value = false
    }
}

const canSave = () =>
    !busy.value && !cart.isEmpty && invoiceDiscountProblem() === '' && !hasDiscountProblem()

// Clearing a whole cart is one wrong tap away from a lost sale, so it takes two
// presses within a few seconds. The first one only arms it.
const clearArmed = ref(false)
let clearTimer = null

function clearCartTwice() {
    if (cart.isEmpty) return

    if (!clearArmed.value) {
        clearArmed.value = true
        clearTimer = setTimeout(() => (clearArmed.value = false), 3000)
        return
    }

    clearTimeout(clearTimer)
    clearArmed.value = false
    clearCart()
}

// Counter shortcuts: F2 confirms and pays, F4 clears the cart. Not while a
// sheet (name search) is open over the screen.
function onShortcut(event) {
    if (!props.embedded || document.querySelector('.scrim')) return

    if (event.key === 'F2') {
        event.preventDefault()
        if (canSave()) save()
    } else if (event.key === 'F4') {
        event.preventDefault()
        clearCartTwice()
    }
}

onMounted(() => window.addEventListener('keydown', onShortcut))
onBeforeUnmount(() => {
    window.removeEventListener('keydown', onShortcut)
    clearTimeout(clearTimer)
})
</script>

<template>
  <div class="screen">
    <div v-if="embedded" class="topbar plain">
      <h1>{{ isExchange() ? 'الاستبدال' : 'السلة' }}</h1>
      <span class="muted">{{ cart.count }} قطعة</span>
      <button
        class="btn quiet danger"
        style="min-height: 40px; padding: 0 12px"
        :disabled="cart.isEmpty"
        @click="clearCartTwice"
      >
        {{ clearArmed ? 'اضغط تاني للتأكيد' : 'مسح السلة (F4)' }}
      </button>
    </div>

    <div v-else class="topbar">
      <button class="icon-btn ghost" aria-label="رجوع" @click="emit('back')">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <path d="M9 6l6 6-6 6" />
        </svg>
      </button>
      <h1>{{ isExchange() ? 'الاستبدال' : 'السلة' }}</h1>
      <span class="muted">{{ cart.count }} قطعة</span>
    </div>

    <p v-if="error" class="msg bad">{{ error }}</p>

    <!-- During an exchange the credit is shown against the new items, so the
         seller can see what the customer pays or gets back. -->
    <div v-if="isExchange()" class="msg ok">
      مرتجع <span class="mono">{{ returnDraft.invoice.number }}</span>
      بقيمة {{ money(returnDraft.refundMinor) }} {{ cur() }}
    </div>

    <div class="list">
      <div
        v-for="line in cart.lines"
        :key="line.variantId"
        class="item"
        :class="{ short: line.variantId === shortVariantId }"
      >
        <div class="row" style="align-items: flex-start">
          <div class="grow">
            <div style="font-weight: 600">{{ line.name }}</div>
            <div class="muted">
              <span v-if="line.label">{{ line.label }} · </span>
              <span class="mono">{{ line.sku }}</span>
            </div>
          </div>
          <div style="text-align: left">
            <div class="total" style="font-size: 17px">{{ money(lineNetMinor(line)) }}</div>
            <div v-if="lineDiscountMinor(line) > 0" class="muted" style="font-size: 12px">
              <s>{{ money(lineGrossMinor(line)) }}</s> · − {{ money(lineDiscountMinor(line)) }}
            </div>
          </div>
        </div>

        <!-- The percentage is only a way of typing: the amount it works out to
             is what is sent and stored. -->
        <div v-if="canDiscount()" class="row" style="margin-top: 6px; gap: 6px">
          <label class="muted" :for="`disc-${line.variantId}`">خصم</label>
          <input
            :id="`disc-${line.variantId}`"
            v-model.number="line.discountValue"
            class="input"
            :class="{ invalid: discountProblem(line) !== '' }"
            type="number"
            inputmode="decimal"
            min="0"
            style="width: 90px; text-align: center"
          >
          <button
            class="btn"
            style="min-height: 38px"
            :aria-pressed="line.discountMode === 'amount'"
            :class="{ primary: line.discountMode === 'amount' }"
            @click="line.discountMode = 'amount'"
          >
            {{ cur() }}
          </button>
          <button
            class="btn"
            style="min-height: 38px"
            :aria-pressed="line.discountMode === 'percent'"
            :class="{ primary: line.discountMode === 'percent' }"
            @click="line.discountMode = 'percent'"
          >
            %
          </button>
        </div>
        <p v-if="discountProblem(line)" class="msg bad" style="margin: 4px 0 0">{{ discountProblem(line) }}</p>

        <div class="row" style="margin-top: 6px">
          <div class="stepper">
            <button
              class="step"
              :aria-label="`زود كمية ${line.name}`"
              :disabled="line.quantity >= line.available"
              @click="setQuantity(line, line.quantity + 1)"
            >
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                   stroke-width="2.2" stroke-linecap="round" aria-hidden="true">
                <path d="M12 5v14M5 12h14" />
              </svg>
            </button>
            <span class="qty">{{ line.quantity }}</span>
            <button
              class="step"
              :aria-label="`قلل كمية ${line.name}`"
              :disabled="line.quantity <= 1"
              @click="setQuantity(line, line.quantity - 1)"
            >
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                   stroke-width="2.2" stroke-linecap="round" aria-hidden="true">
                <path d="M5 12h14" />
              </svg>
            </button>
          </div>

          <button
            class="btn quiet danger"
            style="margin-right: auto; min-height: 44px"
            :aria-label="`حذف ${line.name}`"
            @click="removeLine(line)"
          >
            حذف
          </button>
        </div>

        <p v-if="line.quantity >= line.available" class="muted" style="margin: 4px 0 0">
          ده كل المتاح في الفرع
        </p>
      </div>

      <p v-if="embedded && cart.isEmpty" class="muted center">السلة فاضية. امسح أول قطعة.</p>
      <button v-if="!embedded" class="btn dashed full" @click="emit('back')">Scan قطعة كمان</button>
    </div>

    <div class="card" style="flex: none; display: flex; flex-direction: column; gap: 10px">
      <div class="row">
        <span class="grow muted">الإجمالي قبل الخصم</span>
        <span style="font-weight: 600; font-variant-numeric: tabular-nums">
          {{ money(cart.grossMinor) }}
        </span>
      </div>

      <div v-if="cart.lineDiscountsMinor > 0" class="row">
        <span class="grow muted">خصم الأصناف</span>
        <span style="font-weight: 600; font-variant-numeric: tabular-nums">
          − {{ money(cart.lineDiscountsMinor) }}
        </span>
      </div>

      <div v-if="!isExchange() && canDiscount()" class="row">
        <label class="grow muted" for="discount">خصم على الفاتورة ({{ cur() }})</label>
        <input
          id="discount"
          v-model.number="cart.discount"
          class="input"
          :class="{ invalid: invoiceDiscountProblem() !== '' }"
          type="number"
          inputmode="decimal"
          min="0"
          style="width: 120px; text-align: center"
        >
      </div>

      <p v-if="invoiceDiscountProblem()" class="msg bad">{{ invoiceDiscountProblem() }}</p>

      <div style="height: 1px; background: var(--line)"></div>

      <template v-if="isExchange()">
        <div class="row">
          <span class="grow muted">قيمة المرتجع</span>
          <span style="font-weight: 600; font-variant-numeric: tabular-nums">
            − {{ money(returnDraft.refundMinor) }}
          </span>
        </div>
        <div class="row" style="align-items: baseline">
          <span class="grow" style="font-weight: 700">
            {{ cart.totalMinor >= returnDraft.refundMinor ? 'العميل يدفع' : 'العميل يستلم' }}
          </span>
          <span class="total" style="font-size: 26px">
            {{ money(Math.abs(cart.totalMinor - returnDraft.refundMinor)) }}
          </span>
        </div>
      </template>

      <div v-else class="row" style="align-items: baseline">
        <span class="grow" style="font-weight: 700">الإجمالي</span>
        <span class="total" style="font-size: 26px">{{ money(cart.totalMinor) }}</span>
      </div>

      <div class="field" role="group" aria-labelledby="payment-label">
        <span id="payment-label" class="muted">طريقة الدفع</span>
        <div class="row">
          <button
            v-for="method in session.paymentMethods"
            :key="method.value"
            class="btn grow"
            :class="{ primary: cart.paymentMethod === method.value }"
            :aria-pressed="cart.paymentMethod === method.value"
            @click="cart.paymentMethod = method.value"
          >
            {{ method.label }}
          </button>
        </div>
      </div>

      <button
        class="btn primary big full"
        :disabled="!canSave()"
        @click="save"
      >
        {{ busy ? 'جاري الحفظ…' : (isExchange() ? 'تأكيد الاستبدال' : 'تأكيد ودفع') }}<template v-if="embedded && !busy"> (F2)</template>
      </button>
    </div>
  </div>
</template>
