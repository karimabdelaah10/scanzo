<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { dash } from '../api.js'
import { session } from '../../shared/session.js'
import { currencyLabel } from '../../shared/currency.js'
import { money, fail } from '../lib.js'

/**
 * Sales over a range of days. Every figure is added up by the server
 * (GET /api/reports/sales); this screen only picks the range and lays the
 * answer out. Returns sit apart and are not taken off the sales.
 *
 * The owner may look at every branch or pick one; a manager has no branch
 * filter, because the server only ever answers for their own.
 */

const isOwner = computed(() => session.user?.role === 'owner')
const tz = Intl.DateTimeFormat().resolvedOptions().timeZone

const iso = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
const today = () => iso(new Date())

/** Shortcuts. "Week" is the last seven days including today, so it never
 *  depends on which day a given country starts its week. */
const PRESETS = [
    { key: 'today', label: 'النهاردة', range: () => [today(), today()] },
    {
        key: 'week',
        label: 'آخر 7 أيام',
        range: () => { const d = new Date(); d.setDate(d.getDate() - 6); return [iso(d), today()] },
    },
    {
        key: 'month',
        label: 'الشهر ده',
        range: () => { const d = new Date(); d.setDate(1); return [iso(d), today()] },
    },
]

const from = ref(today())
const to = ref(today())
const branchId = ref('')

const report = ref(null)
const loading = ref(false)
const error = ref('')
let seq = 0

const activePreset = computed(() => PRESETS.find((p) => {
    const [f, t] = p.range()
    return f === from.value && t === to.value
})?.key)

function pick(preset) {
    const [f, t] = preset.range()
    from.value = f
    to.value = t
}

async function load() {
    // A half-typed range (cleared field, or from after to) is not a question yet.
    if (!from.value || !to.value || from.value > to.value) return

    const mine = ++seq
    loading.value = true
    error.value = ''

    try {
        const res = await dash.salesReport({
            from: from.value,
            to: to.value,
            tz,
            branch_id: isOwner.value ? branchId.value : '',
        })
        if (mine === seq) report.value = res.data
    } catch (e) {
        if (mine === seq) error.value = fail(e)
    } finally {
        if (mine === seq) loading.value = false
    }
}

watch([from, to, branchId], load)
onMounted(load)

const label = computed(() => currencyLabel(report.value?.currency))
const mixedCurrency = computed(() => (report.value?.currencies?.length ?? 0) > 1)
const itemName = (r) => [r.name, r.variant].filter(Boolean).join(' · ')
const sellerName = (s) => s.name ?? 'بائع محذوف'
const rangeInvalid = computed(() => from.value && to.value && from.value > to.value)
</script>

<template>
  <div class="stack">
    <div class="page-head">
      <h2>التقارير</h2>
    </div>

    <div class="toolbar">
      <button
        v-for="p in PRESETS"
        :key="p.key"
        class="btn sm"
        :class="{ primary: activePreset === p.key }"
        @click="pick(p)"
      >{{ p.label }}</button>

      <label class="row">
        <span class="muted">من</span>
        <input v-model="from" type="date" class="input" :max="to || today()">
      </label>
      <label class="row">
        <span class="muted">إلى</span>
        <input v-model="to" type="date" class="input" :min="from" :max="today()">
      </label>

      <select v-if="isOwner && session.branches.length > 1" v-model="branchId" class="input" aria-label="الفرع">
        <option value="">كل الفروع</option>
        <option v-for="b in session.branches" :key="b.id" :value="b.id">{{ b.name }}</option>
      </select>
    </div>

    <p v-if="rangeInvalid" class="msg warn">تاريخ البداية بعد تاريخ النهاية.</p>
    <p v-if="error" class="msg bad">{{ error }}</p>

    <template v-if="report">
      <section class="summary" aria-label="ملخص المدى">
        <p v-if="mixedCurrency" class="msg warn" style="grid-column: 1 / -1">
          العملة اتغيّرت خلال المدى ده، فالإجماليات جامعة أكتر من عملة.
        </p>

        <div class="card stat">
          <span class="muted">إجمالي المبيعات</span>
          <span class="total">{{ money(report.total) }} {{ label }}</span>
        </div>
        <div class="card stat">
          <span class="muted">عدد الفواتير</span>
          <span class="total">{{ report.invoices }}</span>
        </div>
        <div class="card stat">
          <span class="muted">متوسط الفاتورة</span>
          <span class="total">{{ money(report.average) }} {{ label }}</span>
        </div>
      </section>

      <div class="cols">
        <div class="card stack">
          <h3>الأكثر مبيعاً — بالكمية <span class="muted">(إيه اللي بيتحرك)</span></h3>
          <p v-if="!report.top_by_quantity.length" class="muted">مفيش مبيعات.</p>
          <div v-for="r in report.top_by_quantity" :key="r.sku" class="row">
            <span class="grow">
              {{ itemName(r) }}
              <span class="mono muted">{{ r.sku }}</span>
              <span v-if="r.returned" class="badge warn">رجع {{ r.returned }}</span>
            </span>
            <span class="num">{{ r.quantity }} قطعة</span>
          </div>
        </div>

        <div class="card stack">
          <h3>الأكثر مبيعاً — بالقيمة <span class="muted">(إيه اللي بيجيب فلوس)</span></h3>
          <p v-if="!report.top_by_value.length" class="muted">مفيش مبيعات.</p>
          <div v-for="r in report.top_by_value" :key="r.sku" class="row">
            <span class="grow">
              {{ itemName(r) }}
              <span class="mono muted">{{ r.sku }}</span>
            </span>
            <span class="num">{{ money(r.total) }} {{ label }}</span>
          </div>
          <p class="muted">القيمة اللي العميل دفعها فعلاً في الصنف: بعد خصم السطر وبعد حصة السطر من خصم الفاتورة.</p>
        </div>
      </div>

      <div class="card stack">
        <h3>حسب البائع</h3>
        <p v-if="!report.by_seller.length" class="muted">مفيش مبيعات.</p>
        <div v-else class="tbl-wrap">
          <table class="tbl stacks">
            <thead><tr><th>البائع</th><th>الفواتير</th><th>المبيعات</th><th>متوسط الفاتورة</th></tr></thead>
            <tbody>
              <tr v-for="s in report.by_seller" :key="s.user_id ?? 'none'">
                <td class="id">{{ sellerName(s) }}</td>
                <td data-label="الفواتير" class="num">{{ s.invoices }}</td>
                <td data-label="المبيعات" class="num">{{ money(s.total) }} {{ label }}</td>
                <td data-label="متوسط الفاتورة" class="num">{{ money(s.average) }} {{ label }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <div v-if="report.by_branch.length > 1" class="card stack">
        <h3>حسب الفرع</h3>
        <div class="tbl-wrap">
          <table class="tbl stacks">
            <thead><tr><th>الفرع</th><th>الفواتير</th><th>المبيعات</th><th>متوسط الفاتورة</th></tr></thead>
            <tbody>
              <tr v-for="b in report.by_branch" :key="b.branch_id">
                <td class="id">{{ b.name ?? '—' }}</td>
                <td data-label="الفواتير" class="num">{{ b.invoices }}</td>
                <td data-label="المبيعات" class="num">{{ money(b.total) }} {{ label }}</td>
                <td data-label="متوسط الفاتورة" class="num">{{ money(b.average) }} {{ label }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Apart from the sales, on purpose: the figures above are NOT reduced by them. -->
      <div class="card stack">
        <h3>المرتجعات <span class="muted">— مش مطروحة من الإجمالي</span></h3>
        <p v-if="!report.returns.count" class="muted">مفيش مرتجعات.</p>
        <div v-else class="row" style="font-weight: 700">
          <span class="grow">إجمالي المرتجعات ({{ report.returns.count }})</span>
          <span class="num">{{ money(report.returns.total) }} {{ label }}</span>
        </div>
      </div>
    </template>

    <p v-if="loading" class="center muted">...</p>
  </div>
</template>

<style scoped>
.summary {
  display: grid;
  gap: 12px;
  grid-template-columns: repeat(3, 1fr);
}
.cols {
  display: grid;
  gap: 12px;
  grid-template-columns: repeat(2, 1fr);
}
.stat { display: flex; flex-direction: column; gap: 4px; }
h3 { margin: 0; font-size: 15px; }
.num { font-variant-numeric: tabular-nums; white-space: nowrap; }

@media (max-width: 900px) {
  .summary, .cols { grid-template-columns: 1fr; }
}
</style>
