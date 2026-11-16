<script setup>
import { onMounted, ref, watch } from 'vue'
import { dash } from '../api.js'
import { href, route } from '../router.js'
import { view, branchName, dateTime, fail } from '../lib.js'

const id = route.value.params.id
const title = route.value.query.name ?? route.value.query.sku ?? ''

const TYPES = {
    purchase: 'استلام',
    sale: 'بيع',
    return: 'مرتجع',
    cancellation: 'إلغاء فاتورة',
    adjustment: 'جرد / تعديل',
    transfer_in: 'تحويل وارد',
    transfer_out: 'تحويل صادر',
    transfer_cancel: 'تحويل ملغي (رجع للمصدر)',
    transfer_settlement: 'تسوية نقص تحويل',
}
const REFS = { Invoice: 'فاتورة', SaleReturn: 'مرتجع' }

const type = ref('')
const rows = ref([])
const page = ref(1)
const lastPage = ref(1)
const loading = ref(false)
const error = ref('')

let seq = 0

async function load(reset = true) {
    const mine = ++seq
    loading.value = true
    error.value = ''
    if (reset) page.value = 1

    try {
        const res = await dash.movements({
            variant_id: id,
            branch_id: view.branchId,
            type: type.value,
            page: page.value,
        })
        if (mine !== seq) return
        rows.value = reset ? res.data : [...rows.value, ...res.data]
        lastPage.value = res.meta.last_page
    } catch (e) {
        if (mine === seq) error.value = fail(e)
    } finally {
        if (mine === seq) loading.value = false
    }
}

watch(type, () => load(true))
onMounted(() => load(true))

function more() {
    if (loading.value) return
    page.value += 1
    load(false)
}

// A manager's session lists only their own branch, so the other side of a
// transfer may not be in it; fall back to saying so rather than to a blank.
const otherBranch = (id) => branchName(id) || 'فرع تاني'

const sign =(n) => (n > 0 ? `+${n}` : String(n))
</script>

<template>
  <div class="stack">
    <div class="page-head">
      <h2>حركات: {{ title }} <span class="muted">— {{ branchName(view.branchId) }}</span></h2>
      <a class="btn quiet" :href="href('/stock')">رجوع للمخزون</a>
    </div>

    <div class="toolbar">
      <select v-model="type" class="input" aria-label="نوع الحركة">
        <option value="">كل الأنواع</option>
        <option v-for="(label, key) in TYPES" :key="key" :value="key">{{ label }}</option>
      </select>
    </div>

    <p v-if="error" class="msg bad">{{ error }}</p>

    <div class="card">
      <p v-if="!loading && !rows.length && !error" class="center muted">مفيش حركات.</p>
      <div v-else class="tbl-wrap">
        <table class="tbl stacks">
          <thead><tr><th>النوع</th><th>التاريخ</th><th>الكمية</th><th>السبب</th></tr></thead>
          <tbody>
            <tr v-for="m in rows" :key="m.id">
              <td class="id">{{ TYPES[m.type] ?? m.type }}</td>
              <td data-label="التاريخ">{{ dateTime(m.occurred_at) }}</td>
              <td data-label="الكمية">
                <span class="diff" :class="m.quantity > 0 ? 'plus' : 'minus'">{{ sign(m.quantity) }}</span>
              </td>
              <td data-label="السبب" class="muted">
                <template v-if="m.transfer_id">
                  {{ m.type === 'transfer_in' ? 'من' : m.type === 'transfer_settlement' ? 'تحويل مع' : 'إلى' }} {{ otherBranch(m.counterpart_branch_id) }}
                </template>
                {{ REFS[m.reference_type] ?? '' }} {{ m.note ?? '' }}
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <p v-if="loading" class="center muted">...</p>
    <button v-else-if="page < lastPage" class="btn full" @click="more">تحميل المزيد</button>
  </div>
</template>
