<script setup>
import { onMounted, ref, watch } from 'vue'
import StockTabs from '../components/StockTabs.vue'
import { dash, allStock } from '../api.js'
import { href } from '../router.js'
import { view, money, fail, debounced, toInt } from '../lib.js'

const search = ref('')
const low = ref(false)
const lowAt = ref('5')
const negative = ref(false)

const rows = ref([])
const page = ref(1)
const lastPage = ref(1)
const loading = ref(false)
const error = ref('')
const truncated = ref(false)

let seq = 0

async function load(reset = true) {
    const mine = ++seq
    loading.value = true
    error.value = ''
    if (reset) page.value = 1

    const params = {
        branch_id: view.branchId,
        search: search.value.trim(),
        low: low.value ? (toInt(lowAt.value) ?? 5) : undefined,
        negative: negative.value ? 1 : undefined,
    }

    try {
        if (low.value || negative.value) {
            // The server filters after paginating, so a filtered view has to
            // read every page or it would miss rows on the later ones.
            const res = await allStock(params)
            if (mine !== seq) return
            rows.value = res.rows
            truncated.value = res.truncated
            lastPage.value = 1
        } else {
            const res = await dash.stock({ ...params, page: page.value })
            if (mine !== seq) return
            rows.value = reset ? res.data : [...rows.value, ...res.data]
            lastPage.value = res.meta.last_page
            truncated.value = false
        }
    } catch (e) {
        if (mine === seq) error.value = fail(e)
    } finally {
        if (mine === seq) loading.value = false
    }
}

const reload = debounced(() => load(true))
watch(search, reload)
watch([low, negative], () => load(true))
watch(lowAt, reload)
onMounted(() => load(true))

function more() {
    if (loading.value) return
    page.value += 1
    load(false)
}

const qtyClass = (q) => (q < 0 ? 'qty-bad' : q <= (toInt(lowAt.value) ?? 5) ? 'qty-low' : '')
</script>

<template>
  <div class="stack">
    <StockTabs />

    <div class="toolbar">
      <input v-model="search" class="input search" type="search" placeholder="ابحث بالاسم أو الكود" aria-label="بحث">
      <button class="btn sm" :class="{ on: low }" :aria-pressed="low" @click="low = !low">قارب يخلص</button>
      <label v-if="low" class="row muted">لحد
        <input v-model="lowAt" class="input code" style="width: 72px; min-height: 38px" inputmode="numeric" aria-label="الحد الأدنى">
      </label>
      <button class="btn sm" :class="{ on: negative }" :aria-pressed="negative" @click="negative = !negative">بالسالب</button>
    </div>

    <p v-if="error" class="msg bad">{{ error }}</p>
    <p v-if="truncated" class="msg warn">النتايج كتيرة وعرضنا جزء منها. ضيّق البحث.</p>
    <p v-if="negative && !loading && rows.length" class="msg warn">
      الرصيد بالسالب معناه إن اتباع أكتر من اللي في السيستم. اعمل جرد للقطع دي.
    </p>

    <div class="card">
      <p v-if="!loading && !rows.length && !error" class="center muted">مفيش نتايج.</p>
      <div v-else class="tbl-wrap">
        <table class="tbl stacks">
          <thead>
            <tr><th>القطعة</th><th>الكود</th><th>السعر</th><th>الرصيد</th><th>في الطريق</th><th></th></tr>
          </thead>
          <tbody>
            <tr v-for="r in rows" :key="r.variant_id" :class="{ off: !r.is_active }">
              <td class="id">
                {{ r.product_name }} · {{ r.label }}
                <span v-if="!r.is_active" class="badge warn">موقوف</span>
              </td>
              <td data-label="الكود"><span class="mono muted">{{ r.sku }}</span></td>
              <td data-label="السعر" class="num">{{ money(r.price) }}</td>
              <td data-label="الرصيد" class="num" :class="qtyClass(r.quantity)">{{ r.quantity }}</td>
              <!-- Heading for the shelf figure stays «الرصيد»: this column is NOT part of it. -->
              <td data-label="في الطريق" class="num muted">{{ r.in_transit_quantity || '' }}</td>
              <td class="actions">
                <a
                  class="btn sm quiet"
                  :href="href(`/stock/movements/${r.variant_id}`, { sku: r.sku, name: `${r.product_name} · ${r.label}` })"
                >الحركات</a>
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
