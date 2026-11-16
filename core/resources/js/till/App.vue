<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { wide } from './layout.js'
import ShiftOpen from './views/ShiftOpen.vue'
import ShiftClose from './views/ShiftClose.vue'
import ShiftPrompt from './views/ShiftPrompt.vue'
import { shift, loadShift, clearShift } from './shift.js'
import Login from './views/Login.vue'
import Workspace from './views/Workspace.vue'
import Sell from './views/Sell.vue'
import Suspended from './views/Suspended.vue'
import Cart from './views/Cart.vue'
import Receipt from './views/Receipt.vue'
import ReturnLookup from './views/ReturnLookup.vue'
import ReturnPick from './views/ReturnPick.vue'
import Invoices from './views/Invoices.vue'
import { session, startSession, signOut } from '../shared/session.js'
import { cart, clearCart, money } from './cart.js'
import { clearReturn, returnDraft } from './returns.js'

/**
 * A handful of screens, and the "router" is a ref. Adding vue-router for
 * sell → cart → receipt would be more moving parts than the thing it routes.
 */

const view = ref('sell')
const done = ref(null)

// On a wide screen the cart is part of the selling screen, so 'cart' and 'sell'
// are the same place there. A window resized across the breakpoint with the
// cart open lands on whichever screen shows it.
const onSell = computed(() => view.value === 'sell' || (view.value === 'cart' && wide.value))
const fatal = ref('')

// A return can be started from two places. Backing out of it goes to the one
// it came from, and the invoice that was open there stays open.
const returnFrom = ref('returnLookup')
const openInvoiceId = ref(null)

function startReturnScreen() {
    returnFrom.value = 'returnLookup'
    view.value = 'returnLookup'
}

function showInvoices() {
    openInvoiceId.value = null
    view.value = 'invoices'
}

function returnFromInvoice() {
    returnFrom.value = 'invoices'
    view.value = 'returnPick'
}

/**
 * Leaving a return without saving it drops it. Otherwise the half-built draft
 * stays behind and the selling screen mistakes it for an exchange in progress.
 */
function abandonReturn() {
    clearReturn()
    view.value = returnFrom.value
}

onMounted(async () => {
    try {
        await startSession()
    } catch (e) {
        fatal.value = e?.message ?? 'مش قادر أوصل للسيرفر'
    }
})

// Which drawer is open on THIS till: asked once the till is known, and again if
// the seller switches to another. Never blocks selling; see shift.js.
watch(
    () => [session.ready, session.deviceId],
    ([ready]) => {
        if (ready && !session.readOnly) loadShift()
        else clearShift()
    },
    { immediate: true },
)

function shiftOpened() {
    view.value = 'sell'
}

function reload() {
    window.location.reload()
}

function sold(invoice) {
    done.value = {
        title: 'تم حفظ الفاتورة',
        number: invoice.number,
        amount: invoice.total,
        note: 'المخزون اتخصم والفاتورة اتسجلت.',
        invoice,
        nextLabel: 'فاتورة جديدة',
    }
    view.value = 'receipt'
}

function refunded(saleReturn) {
    done.value = {
        title: 'تم حفظ المرتجع',
        number: saleReturn.number,
        amount: saleReturn.refund_amount,
        amountLabel: 'المبلغ المسترد',
        note: 'الفلوس تترد للعميل، والقطع السليمة رجعت للمخزون.',
        nextLabel: 'تم',
    }
    view.value = 'receipt'
}

function exchanged(response) {
    const difference = response.meta?.difference ?? '0.00'
    const pays = response.meta?.customer_pays ?? true

    done.value = {
        title: 'تم الاستبدال',
        number: response.data.number,
        amount: difference,
        amountLabel: pays ? 'العميل يدفع' : 'العميل يستلم',
        note: `الفاتورة الجديدة ${response.meta?.exchange_invoice?.number ?? ''}`.trim(),
        invoice: response.meta?.exchange_invoice ?? null,
        nextLabel: 'تم',
    }
    view.value = 'receipt'
}

/** Backing out of an exchange must drop both halves, not just the cart. */
function cancelExchange() {
    clearCart()
    clearReturn()
    view.value = 'sell'
}

async function leave() {
    await signOut()
    clearShift()
    clearCart()
    clearReturn()
    view.value = 'sell'
}
</script>

<template>
  <div class="app" :class="{ counter: wide && onSell && session.ready && !session.readOnly }">
    <div v-if="session.loading" class="screen">
      <p class="center muted">...</p>
    </div>

    <div v-else-if="fatal" class="screen">
      <div class="center">
        <p class="msg bad">{{ fatal }}</p>
        <button class="btn" style="margin-top: 12px" @click="reload">حاول تاني</button>
      </div>
    </div>

    <Login v-else-if="!session.signedIn" />

    <!-- Decided by the one /api/me call: no cart is ever started. Placed before
         Workspace so a suspended shop is told even if no till is picked yet.
         Nothing here about the subscription ending: that never stops selling,
         and the seller can do nothing about it. -->
    <Suspended v-else-if="session.readOnly" />

    <!-- Which branch and till this phone is. Asked once, then remembered. -->
    <Workspace v-else-if="!session.ready" @done="view = 'sell'" />

    <Receipt
      v-else-if="view === 'receipt' && done"
      v-bind="done"
      @next="view = 'sell'"
    />

    <ShiftOpen
      v-else-if="view === 'shiftOpen'"
      @back="view = 'sell'"
      @opened="shiftOpened"
    />

    <ShiftClose
      v-else-if="view === 'shiftClose'"
      @back="view = 'sell'"
      @done="view = 'sell'"
    />

    <ReturnLookup
      v-else-if="view === 'returnLookup'"
      @back="view = 'sell'"
      @found="view = 'returnPick'"
    />

    <Invoices
      v-else-if="view === 'invoices'"
      :open-id="openInvoiceId"
      @open="openInvoiceId = $event"
      @back="view = 'sell'"
      @return="returnFromInvoice"
    />

    <ReturnPick
      v-else-if="view === 'returnPick'"
      @back="abandonReturn"
      @saved="refunded"
      @exchange="view = 'sell'"
    />

    <Cart
      v-else-if="view === 'cart' && !wide"
      @back="view = 'sell'"
      @saved="sold"
      @exchanged="exchanged"
    />

    <Sell
      v-else
      @cart="view = 'cart'"
      @returns="startReturnScreen"
      @invoices="showInvoices"
      @saved="sold"
      @exchanged="exchanged"
    />

    <!-- Once, at the start of work: offer to open the drawer. Closing it sells
         as usual; the banner below stays either way. -->
    <ShiftPrompt
      v-if="session.ready && !session.readOnly && onSell && shift.loaded && (!shift.current || shift.stale) && !shift.promptDismissed"
    />

    <!-- The drawer, as a nudge and never a gate: selling works without it.
         Below the sell screen, not between the v-else-if chain and it. -->
    <div
      v-if="session.ready && !session.readOnly && onSell && shift.loaded && (!shift.current || shift.stale)"
      class="msg warn row"
      style="flex: none; gap: 8px"
    >
      <span class="grow">
        {{ shift.stale ? 'فيه وردية قديمة لسه مفتوحة.' : 'الوردية مش مفتوحة، فالكاش مش بيتحسب.' }}
      </span>
      <button class="btn" style="min-height: 36px; font-size: 13px" @click="view = 'shiftOpen'">افتح الوردية</button>
    </div>

    <!-- Said to whoever opened the next one, until they dismiss it. -->
    <div
      v-if="session.ready && onSell && shift.autoClosed"
      class="msg warn row"
      style="flex: none; gap: 8px"
    >
      <span class="grow">
        وردية {{ shift.autoClosed.opened_by ?? 'سابقة' }} اتقفلت تلقائياً من غير عدّ للدرج. مدير الفرع هيشوف ده.
      </span>
      <button class="btn quiet" style="min-height: 36px; font-size: 13px" @click="shift.autoClosed = null">تمام</button>
    </div>

    <!-- An exchange in progress is the one state a seller can get stuck in, so
         there is always a way out of it. -->
    <button
      v-if="session.ready && returnDraft.invoice && onSell"
      class="btn quiet full danger"
      style="flex: none; min-height: 40px; font-size: 13px"
      @click="cancelExchange"
    >
      إلغاء الاستبدال
    </button>

    <!-- Signing out is deliberately out of the way: it is the one thing a
         seller must not hit by accident mid-sale. -->
    <div
      v-else-if="session.signedIn && onSell"
      class="row"
      style="flex: none; gap: 4px"
    >
      <!-- The way back to management, for anyone who has any. A plain link:
           it leaves for a different app. It shares the sign-out strip so the
           sell screen gains no row of its own. -->
      <a
        v-if="session.user?.role !== 'seller'"
        class="btn quiet grow"
        style="min-height: 40px; font-size: 13px"
        href="/dashboard"
      >
        لوحة الإدارة
      </a>
      <!-- Only a shift from today can be counted. A stale one is replaced by
           opening a new shift, never counted: the drawer now holds today's float. -->
      <button
        v-if="shift.current && !shift.stale && !session.readOnly"
        class="btn quiet grow"
        style="min-height: 40px; font-size: 13px"
        @click="view = 'shiftClose'"
      >
        قفل الوردية
      </button>
      <button
        class="btn quiet grow"
        style="min-height: 40px; font-size: 13px"
        @click="leave"
      >
        خروج ({{ session.user?.name }})
      </button>
    </div>
  </div>
</template>
