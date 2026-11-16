<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { dash } from '../api.js'
import { session } from '../../shared/session.js'
import { shopCurrency } from '../../shared/currency.js'
import { money, dateTime, fail } from '../lib.js'

/**
 * The drawer, seen from the office: every till shift with what the drawer
 * should have held, what the seller counted, and the signed difference.
 *
 * Opens on the shifts that did not balance, because that is what the owner
 * comes here to see. Filtering and the "did not balance" rule are the server's
 * (`GET /api/shifts`); a branch manager only ever receives their own branch, so
 * there is no branch filter for them and nothing here narrows the data.
 *
 * An OPEN shift arrives without its expected cash (blind count, same rule as
 * the till), so this screen has no figure to show for it and never asks for one.
 */

const isOwner = computed(() => session.user?.role === 'owner')
const label = shopCurrency()
const tz = Intl.DateTimeFormat().resolvedOptions().timeZone

const TABS = [
    { key: 'problems', label: 'فيها مشاكل' },
    { key: 'all', label: 'كل الورديات' },
    { key: 'open', label: 'مفتوحة دلوقتي' },
    { key: 'closed', label: 'مقفولة' },
]

const tab = ref('problems')
const branchId = ref('')
const sellerId = ref('')
const from = ref('')
const to = ref('')

const rows = ref([])
const page = ref(1)
const lastPage = ref(1)
const loading = ref(false)
const error = ref('')

// The seller picker. The owner can list the team; a manager cannot (the
// endpoint is owner-only), so for them it is built from the shifts the server
// has returned so far, and only ever grows, so picking a seller does not empty it.
const sellers = ref(new Map())

function remember(shift) {
    if (shift.user_id && shift.opened_by && !sellers.value.has(shift.user_id)) {
        sellers.value = new Map(sellers.value).set(shift.user_id, shift.opened_by)
    }
}

const sellerOptions = computed(() => [...sellers.value].sort((a, b) => a[1].localeCompare(b[1], 'ar')))

let seq = 0

const params = () => ({
    tz,
    problems: tab.value === 'problems' ? 1 : '',
    status: tab.value === 'open' || tab.value === 'closed' ? tab.value : '',
    branch_id: isOwner.value ? branchId.value : '',
    user_id: sellerId.value,
    from: from.value,
    to: to.value,
})

async function load(reset = true) {
    const mine = ++seq
    loading.value = true
    if (reset) { page.value = 1; error.value = '' }

    try {
        const res = await dash.shifts({ ...params(), page: page.value })
        if (mine !== seq) return
        rows.value = reset ? res.data : [...rows.value, ...res.data]
        lastPage.value = res.meta.last_page
        res.data.forEach(remember)
    } catch (e) {
        if (mine === seq) error.value = fail(e)
    } finally {
        if (mine === seq) loading.value = false
    }
}

function more() {
    if (loading.value) return
    page.value += 1
    load(false)
}

watch([tab, branchId, sellerId, from, to], () => load(true))

onMounted(async () => {
    load(true)
    if (isOwner.value) {
        try {
            const { data } = await dash.users({ role: 'seller' })
            data.forEach((u) => remember({ user_id: u.id, opened_by: u.name }))
        } catch (e) { /* the picker still fills from the shifts */ }
    }
})

/* ---------- reading one shift ---------- */

const isOpen = (s) => s.status === 'open'
const isAuto = (s) => s.close_kind === 'auto'
const diffNumber = (s) => (s.difference == null ? null : Number(s.difference))

/** 'short' | 'over' | 'even' | null (open, or nobody counted) */
function outcome(s) {
    if (isOpen(s) || isAuto(s)) return null
    const d = diffNumber(s)
    if (d === null) return null
    return d < 0 ? 'short' : d > 0 ? 'over' : 'even'
}

// Not money() on the signed value: it reads a minus sign as "not a number".
// The sign is shown on its own, in front of the amount.
const amount = (value) => money(String(value).replace('-', ''))

function signed(s) {
    const d = diffNumber(s)
    return `${d < 0 ? '−' : d > 0 ? '+' : ''}${amount(s.difference)} ${label}`
}

const branchLabel = (id) => session.branches.find((b) => b.id === id)?.name ?? ''
const device = (s) => s.device_name || s.device_code || '—'
const emptyText = computed(() => (tab.value === 'problems'
    ? 'مفيش ورديات فيها فرق أو اتقفلت من غير عدّ. كله مظبوط.'
    : 'مفيش ورديات بالفلاتر دي.'))
</script>

<template>
  <div class="stack">
    <div class="page-head">
      <h2>الورديات</h2>
    </div>

    <div class="tabs" role="tablist" aria-label="الحالة">
      <button
        v-for="t in TABS"
        :key="t.key"
        role="tab"
        class="btn sm"
        :class="{ primary: tab === t.key }"
        :aria-selected="tab === t.key"
        @click="tab = t.key"
      >{{ t.label }}</button>
    </div>

    <div class="toolbar">
      <select v-if="isOwner && session.branches.length > 1" v-model="branchId" class="input" aria-label="الفرع">
        <option value="">كل الفروع</option>
        <option v-for="b in session.branches" :key="b.id" :value="b.id">{{ b.name }}</option>
      </select>

      <select v-model="sellerId" class="input" aria-label="البائع">
        <option value="">كل البائعين</option>
        <option v-for="[id, name] in sellerOptions" :key="id" :value="id">{{ name }}</option>
      </select>

      <label class="muted">من <input v-model="from" type="date" class="input" aria-label="من تاريخ" :max="to || undefined"></label>
      <label class="muted">إلى <input v-model="to" type="date" class="input" aria-label="إلى تاريخ" :min="from || undefined"></label>
    </div>

    <p v-if="error" class="msg bad">{{ error }}</p>

    <div class="card">
      <p v-if="!loading && !rows.length && !error" class="center muted">{{ emptyText }}</p>
      <div v-else class="tbl-wrap">
        <table class="tbl stacks">
          <thead>
            <tr>
              <th>الفرع</th><th>الجهاز</th><th>البائع</th><th>الفتح</th><th>القفل</th>
              <th>المتوقع</th><th>الفعلي</th><th>الفرق</th><th>ملاحظة البائع</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="s in rows" :key="s.id" :class="{ short: outcome(s) === 'short' }">
              <td class="id">{{ branchLabel(s.branch_id) }}</td>
              <td data-label="الجهاز">{{ device(s) }}</td>
              <td data-label="البائع">
                <a href="#" @click.prevent="sellerId = s.user_id">{{ s.opened_by ?? '—' }}</a>
              </td>
              <td data-label="الفتح">{{ dateTime(s.opened_at) }}</td>
              <td data-label="القفل">
                <span v-if="isOpen(s)" class="badge ok">مفتوحة دلوقتي</span>
                <template v-else>{{ dateTime(s.closed_at) }}</template>
              </td>

              <!-- An open shift has no expected figure to show, by design. -->
              <td data-label="المتوقع" class="num">
                <template v-if="isOpen(s)">—</template>
                <template v-else>{{ money(s.expected_cash) }} {{ label }}</template>
              </td>
              <td data-label="الفعلي" class="num">
                <template v-if="isOpen(s) || isAuto(s)">—</template>
                <template v-else>{{ money(s.counted_cash) }} {{ label }}</template>
              </td>
              <td data-label="الفرق" class="num">
                <span v-if="isOpen(s)" class="muted">لسه مفتوحة</span>
                <span v-else-if="isAuto(s)" class="badge warn">اتقفلت من غير عدّ</span>
                <span v-else-if="outcome(s) === 'short'" class="badge bad">عجز {{ signed(s) }}</span>
                <span v-else-if="outcome(s) === 'over'" class="badge warn">زيادة {{ signed(s) }}</span>
                <span v-else class="badge ok">مظبوطة</span>
              </td>
              <td data-label="ملاحظة البائع">{{ s.note || '' }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <p v-if="loading" class="center muted">...</p>
    <button v-else-if="page < lastPage" class="btn full" @click="more">تحميل المزيد</button>
  </div>
</template>

<style scoped>
.tabs { display: flex; flex-wrap: wrap; gap: 8px; }
.num { font-variant-numeric: tabular-nums; white-space: nowrap; }
/* A shortage is the thing this screen is for: the row itself leans red. */
tr.short { background: var(--bad-soft); }
</style>
