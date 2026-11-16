<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { dash } from '../api.js'
import { go, href } from '../router.js'
import { session } from '../../shared/session.js'
import { currencyLabel } from '../../shared/currency.js'
import { money, fail } from '../lib.js'

/**
 * What was sold. One screen for a day: the totals on top (worked out by the
 * server, never summed here), the day's invoices underneath, 25 a page.
 *
 * The owner can look at every branch or pick one; a branch manager has no
 * branch filter at all, because the server only ever returns their own branch.
 * Nothing here narrows the data to make that true.
 */

const isOwner = computed(() => session.user?.role === 'owner')

// The shop's own calendar day, in the browser's time zone — not UTC's.
const localToday = () => {
    const d = new Date()
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}
const tz = Intl.DateTimeFormat().resolvedOptions().timeZone

const date = ref(localToday())
const branchId = ref('')      // '' = every branch (owner only)
const sellerId = ref('')

const summary = ref(null)
const rows = ref([])
const page = ref(1)
const lastPage = ref(1)
const loadingList = ref(false)
const loadingSummary = ref(false)
const error = ref('')

let listSeq = 0
let summarySeq = 0

const dayParams = () => ({
    date: date.value || localToday(),
    tz,
    branch_id: isOwner.value ? branchId.value : '',
})

async function loadSummary() {
    const mine = ++summarySeq
    loadingSummary.value = true

    try {
        // Deliberately without the seller filter: the seller list below comes
        // from this answer, and filtering it by one seller would empty it.
        const res = await dash.invoiceSummary(dayParams())
        if (mine === summarySeq) summary.value = res.data
    } catch (e) {
        if (mine === summarySeq) error.value = fail(e)
    } finally {
        if (mine === summarySeq) loadingSummary.value = false
    }
}

async function loadList(reset = true) {
    const mine = ++listSeq
    loadingList.value = true
    if (reset) page.value = 1

    try {
        const res = await dash.invoices({ ...dayParams(), user_id: sellerId.value, page: page.value })
        if (mine !== listSeq) return
        rows.value = reset ? res.data : [...rows.value, ...res.data]
        lastPage.value = res.meta.last_page
    } catch (e) {
        if (mine === listSeq) error.value = fail(e)
    } finally {
        if (mine === listSeq) loadingList.value = false
    }
}

function reloadAll() {
    error.value = ''
    loadSummary()
    loadList(true)
}

// A new day or branch changes both the totals and the list; a seller only the list.
watch([date, branchId], () => { sellerId.value = ''; reloadAll() })
watch(sellerId, () => loadList(true))
onMounted(reloadAll)

function more() {
    if (loadingList.value) return
    page.value += 1
    loadList(false)
}

const time = (iso) => (iso
    ? new Date(iso).toLocaleTimeString('ar-EG', { timeStyle: 'short' })
    : '')

const label = computed(() => currencyLabel(summary.value?.currency))
const mixedCurrency = computed(() => (summary.value?.currencies?.length ?? 0) > 1)
const sellers = computed(() => summary.value?.by_seller ?? [])
const sellerName = (s) => s.name ?? 'بائع محذوف'

const open = (id) => go(`/invoices/${id}`)
</script>

<template>
  <div class="stack">
    <div class="page-head">
      <h2>الفواتير</h2>
    </div>

    <div class="toolbar">
      <input v-model="date" type="date" class="input" aria-label="اليوم" :max="localToday()">

      <select v-if="isOwner && session.branches.length > 1" v-model="branchId" class="input" aria-label="الفرع">
        <option value="">كل الفروع</option>
        <option v-for="b in session.branches" :key="b.id" :value="b.id">{{ b.name }}</option>
      </select>

      <select v-model="sellerId" class="input" aria-label="البائع">
        <option value="">كل البائعين</option>
        <option v-for="s in sellers" :key="s.user_id ?? 'none'" :value="s.user_id ?? ''" :disabled="!s.user_id">
          {{ sellerName(s) }}
        </option>
      </select>
    </div>

    <p v-if="error" class="msg bad">{{ error }}</p>

    <!-- The day's totals -->
    <section v-if="summary" class="summary" aria-label="ملخص اليوم">
      <p v-if="mixedCurrency" class="msg warn" style="grid-column: 1 / -1">
        العملة اتغيّرت خلال اليوم ده، فالإجماليات دي جامعة أكتر من عملة. راجع الفواتير واحدة واحدة.
      </p>

      <div class="card stat">
        <span class="muted">إجمالي المبيعات</span>
        <span class="total">{{ money(summary.total) }} {{ label }}</span>
      </div>
      <div class="card stat">
        <span class="muted">عدد الفواتير</span>
        <span class="total">{{ summary.invoices }}</span>
      </div>
      <div class="card stat">
        <span class="muted">متوسط الفاتورة</span>
        <span class="total">{{ money(summary.average) }} {{ label }}</span>
      </div>

      <div class="card stack">
        <h3>حسب طريقة الدفع</h3>
        <p v-if="!summary.by_payment_method.length" class="muted">مفيش مبيعات.</p>
        <div v-for="m in summary.by_payment_method" :key="m.method" class="row">
          <span class="grow">{{ m.label }} <span class="muted">({{ m.invoices }})</span></span>
          <span class="num">{{ money(m.total) }} {{ label }}</span>
        </div>
      </div>

      <div class="card stack">
        <h3>حسب البائع</h3>
        <p v-if="!sellers.length" class="muted">مفيش مبيعات.</p>
        <div v-for="s in sellers" :key="s.user_id ?? 'none'" class="row">
          <span class="grow">{{ sellerName(s) }} <span class="muted">({{ s.invoices }})</span></span>
          <span class="num">{{ money(s.total) }} {{ label }}</span>
        </div>
      </div>

      <!-- Apart from the sales, on purpose: the figures above are NOT reduced by them. -->
      <div class="card stack returns">
        <h3>المرتجعات <span class="muted">— مش مطروحة من الإجمالي</span></h3>
        <p v-if="!summary.returns.count" class="muted">مفيش مرتجعات.</p>
        <template v-else>
          <div v-for="r in summary.returns.by_method" :key="r.method" class="row">
            <span class="grow">{{ r.label }} <span class="muted">({{ r.count }})</span></span>
            <span class="num">{{ money(r.total) }} {{ label }}</span>
          </div>
          <div class="row" style="font-weight: 700">
            <span class="grow">إجمالي المرتجعات ({{ summary.returns.count }})</span>
            <span class="num">{{ money(summary.returns.total) }} {{ label }}</span>
          </div>
        </template>
      </div>
    </section>
    <p v-else-if="loadingSummary" class="center muted">...</p>

    <!-- The day's invoices -->
    <div class="card">
      <p v-if="!loadingList && !rows.length && !error" class="center muted">مفيش فواتير في اليوم ده.</p>
      <div v-else class="tbl-wrap">
        <table class="tbl stacks">
          <thead>
            <tr>
              <th>الرقم</th><th>الوقت</th><th>البائع</th><th>القطع</th>
              <th>الإجمالي</th><th>الدفع</th><th>الحالة</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="inv in rows" :key="inv.id" class="clickable" @click="open(inv.id)">
              <td class="id">
                <a class="mono" :href="href(`/invoices/${inv.id}`)" @click.stop>{{ inv.number }}</a>
              </td>
              <td data-label="الوقت">{{ time(inv.issued_at) }}</td>
              <td data-label="البائع">{{ inv.seller?.name ?? '—' }}</td>
              <td data-label="القطع" class="num">{{ inv.items_count }}</td>
              <td data-label="الإجمالي" class="num">{{ money(inv.total) }} {{ currencyLabel(inv.currency) }}</td>
              <td data-label="الدفع">{{ inv.payment_method_label }}</td>
              <td data-label="الحالة">
                <span v-if="inv.has_returns" class="badge warn">فيه مرتجع</span>
                <span v-if="inv.status === 'voided'" class="badge bad">ملغية</span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <p v-if="loadingList" class="center muted">...</p>
    <button v-else-if="page < lastPage" class="btn full" @click="more">تحميل المزيد</button>
  </div>
</template>

<style scoped>
.summary {
  display: grid;
  gap: 12px;
  grid-template-columns: repeat(3, 1fr);
}
.stat { display: flex; flex-direction: column; gap: 4px; }
.summary h3 { margin: 0; font-size: 15px; }
.num { font-variant-numeric: tabular-nums; }
.clickable { cursor: pointer; }
.clickable:hover { background: var(--bg); }

/* Phone: the three headline numbers stay in a row of their own only if they
   fit; the splits go one under another. */
@media (max-width: 900px) {
  .summary { grid-template-columns: 1fr; }
}
</style>
