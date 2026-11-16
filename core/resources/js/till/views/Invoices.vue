<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import ReceiptDocument from '../../shared/ReceiptDocument.vue'
import VoidPanel from '../../shared/VoidPanel.vue'
import { session } from '../../shared/session.js'
import { till, ApiError } from '../../shared/api.js'
import { currencyLabel } from '../../shared/currency.js'
import { money, toMinor } from '../cart.js'
import { startReturn } from '../returns.js'

/**
 * Today's invoices, and any one of them opened again.
 *
 * Its main use is a return: the customer comes back without the paper, and the
 * seller finds the sale here in a few seconds instead of asking for a number.
 *
 * Cards rather than a table — this is a phone screen first. Which invoice is
 * open is held by the parent (`openId`), so backing out of a return lands on
 * the invoice that was open and not on the top of the list.
 *
 * What may be listed is decided by the server: a seller gets their own sales,
 * a manager the whole branch. Nothing here narrows the data to make that so.
 */

const props = defineProps({
    openId: { type: String, default: null },
})

const emit = defineEmits(['back', 'open', 'return'])

// The shop's own calendar day, in this browser's time zone — not UTC's.
const localToday = () => {
    const d = new Date()
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}
const tz = Intl.DateTimeFormat().resolvedOptions().timeZone

const rows = ref([])
const page = ref(1)
const lastPage = ref(1)
const loading = ref(false)
const error = ref('')

const invoice = ref(null)
const opening = ref(false)
const openError = ref('')
const returning = ref(false)

const showsSeller = computed(() => session.user?.role !== 'seller')

let listSeq = 0

async function load(reset = true) {
    const mine = ++listSeq
    if (reset) page.value = 1

    loading.value = true
    error.value = ''

    try {
        const res = await till.invoicesOfDay({
            date: localToday(),
            tz,
            branchId: session.branchId,
            page: page.value,
        })
        if (mine !== listSeq) return

        rows.value = reset ? res.data : [...rows.value, ...res.data]
        lastPage.value = res.meta.last_page
    } catch (e) {
        if (mine === listSeq) error.value = e instanceof ApiError ? e.message : 'حصل خطأ'
    } finally {
        if (mine === listSeq) loading.value = false
    }
}

function more() {
    if (loading.value || page.value >= lastPage.value) return
    page.value++
    load(false)
}

async function openInvoice(id) {
    invoice.value = null
    openError.value = ''

    if (!id) return

    opening.value = true

    try {
        const res = await till.invoice(id)
        // Closed or switched while it was loading.
        if (props.openId === id) invoice.value = res.data
    } catch (e) {
        if (props.openId === id) openError.value = e instanceof ApiError ? e.message : 'حصل خطأ'
    } finally {
        opening.value = false
    }
}

watch(() => props.openId, openInvoice)

onMounted(() => {
    load()
    if (props.openId) openInvoice(props.openId)
})

const time = (iso) =>
    iso ? new Date(iso).toLocaleTimeString('ar-EG', { timeStyle: 'short' }) : ''

const amount = (value) => money(toMinor(value))

/**
 * Whether to offer a return. A courtesy only: the server decides, and says why
 * when it refuses. Sellers return only if the shop allows it.
 */
const mayReturn = computed(
    () => session.user?.role !== 'seller' || session.business?.sellers_may_return === true,
)

const outstanding = computed(
    () => (invoice.value?.items ?? []).reduce((sum, item) => sum + item.returnable_quantity, 0),
)

const isVoided = computed(() => invoice.value?.status === 'voided')

async function startReturnFromInvoice() {
    if (returning.value || !invoice.value) return

    returning.value = true
    openError.value = ''

    try {
        // The same lookup a scanned receipt goes through, so the window, the
        // voided check and the branch check are the server's and not repeated.
        const res = await till.lookupReceipt(invoice.value.number)
        startReturn(res.data, res.meta)
        emit('return')
    } catch (e) {
        openError.value = e instanceof ApiError ? e.message : 'حصل خطأ'
    } finally {
        returning.value = false
    }
}

// The server returns the invoice as it now stands; the day's list is reloaded
// so the row shows «ملغية» when the user goes back to it.
function onVoided(updated) {
    invoice.value = updated
    load()
}

function back() {
    if (props.openId) emit('open', null)
    else emit('back')
}
</script>

<template>
  <div class="screen">
    <div class="topbar">
      <button class="icon-btn ghost" aria-label="رجوع" @click="back">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <path d="M9 6l6 6-6 6" />
        </svg>
      </button>
      <h1>{{ openId ? 'الفاتورة' : 'فواتير النهارده' }}</h1>
      <span v-if="invoice" class="mono muted">{{ invoice.number }}</span>
    </div>

    <!-- one invoice -->
    <div v-if="openId" class="list">
      <p v-if="openError" class="msg bad">{{ openError }}</p>
      <p v-if="opening" class="center muted">...</p>

      <template v-if="invoice">
        <!-- Only for a manager or owner, inside the window: the server says so in can_void.
             Kept above the v-if chain below, which it must not interrupt. -->
        <VoidPanel :invoice="invoice" @voided="onVoided" />

        <p v-if="isVoided" class="msg warn">
          الفاتورة دي ملغية<span v-if="invoice.void_reason"> — {{ invoice.void_reason }}</span>.
        </p>

        <button
          v-else-if="mayReturn && outstanding > 0"
          class="btn primary big full"
          style="flex: none"
          :disabled="returning"
          @click="startReturnFromInvoice"
        >
          مرتجع من الفاتورة دي
        </button>

        <p v-else-if="mayReturn && outstanding === 0" class="msg ok">
          كل قطع الفاتورة دي اتسترجعت.
        </p>

        <ReceiptDocument :invoice="invoice" />
      </template>
    </div>

    <!-- the day's list -->
    <div v-else class="list">
      <p v-if="error" class="msg bad">{{ error }}</p>

      <p v-else-if="loading && !rows.length" class="center muted">...</p>

      <p v-else-if="!rows.length" class="center muted">مفيش فواتير النهارده لسه.</p>

      <button
        v-for="row in rows"
        :key="row.id"
        class="card tight invoice-card"
        @click="emit('open', row.id)"
      >
        <span class="row" style="justify-content: space-between; align-items: baseline">
          <span class="mono" style="font-weight: 700">{{ row.number }}</span>
          <span class="total" style="font-size: 18px">
            {{ amount(row.total) }} <span class="muted">{{ currencyLabel(row.currency) }}</span>
          </span>
        </span>

        <span class="row wrap" style="gap: 8px; margin-top: 4px">
          <span class="muted">{{ time(row.issued_at) }}</span>
          <span class="muted">· {{ row.items_count }} قطعة</span>
          <span v-if="showsSeller && row.seller?.name" class="muted">· {{ row.seller.name }}</span>
          <span v-if="row.status === 'voided'" class="chip warn" style="min-height: 26px">ملغية</span>
          <span v-if="row.has_returns" class="chip warn" style="min-height: 26px">فيها مرتجع</span>
        </span>
      </button>

      <button v-if="page < lastPage" class="btn full" style="flex: none" :disabled="loading" @click="more">
        {{ loading ? '...' : 'عرض المزيد' }}
      </button>
    </div>
  </div>
</template>

<style scoped>
.invoice-card { width: 100%; display: block; text-align: start; min-height: 64px; flex: none; }
</style>
