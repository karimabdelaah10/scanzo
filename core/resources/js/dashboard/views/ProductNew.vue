<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import Chips from '../components/Chips.vue'
import { dash } from '../api.js'
import { session } from '../../shared/session.js'
import { ApiError } from '../../shared/api.js'
import { href } from '../router.js'
import { view, toMinor, toDecimal, toInt, labelsUrl, fail, normalizeNumber } from '../lib.js'

const MAX_VARIANTS = 200 // the API's own limit
const showCost = session.user.sees_cost

const name = ref('')
const categoryId = ref('')
const categories = ref([])
const newCategory = ref('')
const sizes = ref([])
const colors = ref([])
const bulk = reactive({ price: '', cost: '', stock: '' })
// Only what the user typed into a row itself. A row with nothing here follows
// the "apply to all" values, so changing the top price never fights a typed one.
const overrides = reactive({})
const stockBranchId = ref(view.branchId)

const tried = ref(false)
const busy = ref(false)
const error = ref('')
const saved = ref(null)

onMounted(async () => {
    try {
        const { data } = await dash.categories()
        categories.value = data.flatMap((c) => [c, ...(c.children ?? []).map((k) => ({ ...k, child: true }))])
    } catch (e) { /* the product can still be saved without a category */ }
})

const rows = computed(() => {
    const S = sizes.value.length ? sizes.value : [null]
    const C = colors.value.length ? colors.value : [null]

    return S.flatMap((size) => C.map((color) => ({
        key: JSON.stringify([size, color]),
        size,
        color,
        label: [size, color].filter(Boolean).join(' / ') || 'قطعة واحدة',
    })))
})

function value(row, field) {
    const own = overrides[row.key]?.[field]
    return own !== undefined && own !== '' ? own : bulk[field]
}

function setOverride(row, field, text) {
    overrides[row.key] ??= {}
    if (text.trim() === '') delete overrides[row.key][field]
    else overrides[row.key][field] = text
}

const edited = (row, field) => overrides[row.key]?.[field] !== undefined

/** Per-row problems, shown only once the user has tried to save. */
function rowProblems(row) {
    const out = {}
    const price = value(row, 'price')
    const cost = value(row, 'cost')
    const stock = value(row, 'stock')

    if (price.trim() === '' || toMinor(price) === null) out.price = true
    if (showCost && cost.trim() !== '' && toMinor(cost) === null) out.cost = true
    if (stock.trim() !== '' && (toInt(stock) === null || toInt(stock) < 0)) out.stock = true

    return out
}

const openingTotal = computed(() => rows.value.reduce((sum, r) => {
    const n = toInt(value(r, 'stock'))
    return sum + (n > 0 ? n : 0)
}, 0))

const problems = computed(() => {
    const list = []
    if (!name.value.trim()) list.push('اكتب اسم المنتج.')
    if (rows.value.length > MAX_VARIANTS) list.push(`${rows.value.length} قطعة كتير. الحد الأقصى ${MAX_VARIANTS} في المنتج الواحد.`)
    if (rows.value.some((r) => rowProblems(r).price)) list.push('السعر مطلوب لكل قطعة.')
    if (rows.value.some((r) => rowProblems(r).cost || rowProblems(r).stock)) list.push('في تكلفة أو رصيد مش رقم صحيح.')
    if (openingTotal.value > 0 && !stockBranchId.value) list.push('اختار الفرع اللي فيه الرصيد الافتتاحي.')
    return list
})

async function addCategory() {
    const text = newCategory.value.trim()
    if (!text) return

    try {
        const { data } = await dash.createCategory(text)
        categories.value.push(data)
        categoryId.value = data.id
        newCategory.value = ''
    } catch (e) {
        error.value = fail(e)
    }
}

async function save() {
    tried.value = true
    error.value = ''

    if (problems.value.length || busy.value) return
    busy.value = true

    const variants = rows.value.map((r) => {
        const out = { price: toDecimal(toMinor(value(r, 'price'))) }
        if (r.size) out.size = r.size
        if (r.color) out.color = r.color
        if (showCost && toMinor(value(r, 'cost')) !== null) out.cost = toDecimal(toMinor(value(r, 'cost')))
        if (toInt(value(r, 'stock')) > 0) out.opening_stock = toInt(value(r, 'stock'))
        return out
    })

    try {
        const { data } = await dash.createProduct({
            name: name.value.trim(),
            category_id: categoryId.value || null,
            variants,
            opening_stock_branch_id: openingTotal.value > 0 ? stockBranchId.value : null,
        })

        saved.value = { product: data, openingTotal: openingTotal.value, branchId: stockBranchId.value }
    } catch (e) {
        error.value = fail(e)
        // No answer at all: the product may have been saved. Saying so stops a
        // second tap from creating the same product twice.
        if (e instanceof ApiError && e.code === 'offline') {
            error.value += ' ممكن المنتج يكون اتحفظ — راجع قائمة المنتجات قبل ما تحاول تاني.'
        }
    } finally {
        busy.value = false
    }
}

function another() {
    saved.value = null
    tried.value = false
    name.value = ''
    sizes.value = []
    colors.value = []
    Object.assign(bulk, { price: '', cost: '', stock: '' })
    Object.keys(overrides).forEach((k) => delete overrides[k])
}

const printUrl = computed(() => saved.value && labelsUrl({
    productId: saved.value.product.id,
    branchId: saved.value.branchId ?? view.branchId,
    copies: saved.value.openingTotal > 0 ? 'stock' : 'one',
}))
</script>

<template>
  <div class="stack">
    <div class="page-head">
      <h2>منتج جديد</h2>
      <a class="btn quiet" :href="href('/products')">رجوع للمنتجات</a>
    </div>

    <!-- After saving -->
    <div v-if="saved" class="card stack">
      <p class="msg ok">
        اتحفظ «{{ saved.product.name }}» بـ {{ saved.product.variants.length }} قطعة.
        الأكواد اتولّدت تلقائي.
      </p>

      <div class="row wrap">
        <a class="btn primary big" :href="printUrl" target="_blank" rel="noopener">اطبع الاستيكرات</a>
        <button class="btn big" @click="another">منتج جديد</button>
        <a class="btn big quiet" :href="href('/products')">قائمة المنتجات</a>
      </div>
      <p v-if="!saved.openingTotal" class="muted">
        مفيش رصيد افتتاحي، فالاستيكرات هتتطبع قطعة واحدة لكل مقاس/لون. بعد ما تستلم البضاعة تقدر تطبع بعدد الرصيد من شاشة الاستيكرات.
      </p>

      <div class="tbl-wrap">
        <table class="tbl stacks">
          <thead><tr><th>القطعة</th><th>الكود</th><th>السعر</th></tr></thead>
          <tbody>
            <tr v-for="v in saved.product.variants" :key="v.id">
              <td class="id">{{ v.label }}</td>
              <td data-label="الكود"><span class="mono">{{ v.sku }}</span></td>
              <td data-label="السعر" class="num">{{ v.price }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <form v-else class="stack" novalidate @submit.prevent="save">
      <div class="card stack">
        <div class="two">
          <div class="field">
            <label for="pname">اسم المنتج</label>
            <input id="pname" v-model="name" class="input" :class="{ invalid: tried && !name.trim() }" maxlength="255" autofocus>
          </div>
          <div class="field">
            <label for="pcat">التصنيف (اختياري)</label>
            <select id="pcat" v-model="categoryId" class="input">
              <option value="">من غير تصنيف</option>
              <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.child ? '— ' : '' }}{{ c.name }}</option>
            </select>
          </div>
        </div>
        <div class="row">
          <input
            v-model="newCategory"
            class="input grow"
            placeholder="تصنيف جديد"
            aria-label="اسم تصنيف جديد"
            @keydown.enter.prevent="addCategory"
          >
          <button type="button" class="btn sm" :disabled="!newCategory.trim()" @click="addCategory">أضف التصنيف</button>
        </div>
      </div>

      <div class="card stack">
        <div class="two">
          <div class="field">
            <label>المقاسات — اكتب مقاس واضغط Enter</label>
            <Chips v-model="sizes" placeholder="S  M  L  XL ..." label="المقاسات" />
          </div>
          <div class="field">
            <label>الألوان — اكتب لون واضغط Enter</label>
            <Chips v-model="colors" placeholder="أسود  أبيض ..." label="الألوان" />
          </div>
        </div>
        <p class="muted">
          هيتولّد سطر لكل مقاس × لون ({{ rows.length }} سطر).
          من غير مقاسات ولا ألوان هيتعمل قطعة واحدة.
        </p>
      </div>

      <div class="card stack">
        <div class="three">
          <div class="field">
            <label for="bp">السعر لكل القطع</label>
            <input id="bp" v-model="bulk.price" class="input code" inputmode="decimal" placeholder="مثلاً 450">
          </div>
          <div v-if="showCost" class="field">
            <label for="bc">التكلفة لكل القطع (اختياري)</label>
            <input id="bc" v-model="bulk.cost" class="input code" inputmode="decimal">
          </div>
          <div class="field">
            <label for="bs">الرصيد الافتتاحي لكل قطعة (اختياري)</label>
            <input id="bs" v-model="bulk.stock" class="input code" inputmode="numeric">
          </div>
        </div>

        <div v-if="session.user.sees_all_branches && session.branches.length > 1 && openingTotal > 0" class="field" style="max-width: 320px">
          <label for="sb">الرصيد الافتتاحي في فرع</label>
          <select id="sb" v-model="stockBranchId" class="input">
            <option v-for="b in session.branches" :key="b.id" :value="b.id">{{ b.name }}</option>
          </select>
        </div>

        <div class="tbl-wrap">
          <table class="tbl stacks">
            <thead>
              <tr>
                <th>القطعة</th>
                <th>السعر *</th>
                <th v-if="showCost">التكلفة</th>
                <th>الرصيد</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="r in rows" :key="r.key">
                <td class="id">{{ r.label }}</td>
                <td data-label="السعر">
                  <input
                    class="input cell"
                    :class="{ edited: edited(r, 'price'), invalid: tried && rowProblems(r).price }"
                    inputmode="decimal"
                    :value="overrides[r.key]?.price ?? ''"
                    :placeholder="normalizeNumber(bulk.price)"
                    :aria-label="`سعر ${r.label}`"
                    @input="setOverride(r, 'price', $event.target.value)"
                  >
                </td>
                <td v-if="showCost" data-label="التكلفة">
                  <input
                    class="input cell"
                    :class="{ edited: edited(r, 'cost'), invalid: tried && rowProblems(r).cost }"
                    inputmode="decimal"
                    :value="overrides[r.key]?.cost ?? ''"
                    :placeholder="normalizeNumber(bulk.cost)"
                    :aria-label="`تكلفة ${r.label}`"
                    @input="setOverride(r, 'cost', $event.target.value)"
                  >
                </td>
                <td data-label="الرصيد">
                  <input
                    class="input cell"
                    :class="{ edited: edited(r, 'stock'), invalid: tried && rowProblems(r).stock }"
                    inputmode="numeric"
                    :value="overrides[r.key]?.stock ?? ''"
                    :placeholder="normalizeNumber(bulk.stock)"
                    :aria-label="`رصيد ${r.label}`"
                    @input="setOverride(r, 'stock', $event.target.value)"
                  >
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <p class="muted">الخانة الفاضية بتاخد القيمة اللي فوق. اكتب في أي خانة عشان تغيّرها للسطر ده بس.</p>
      </div>

      <div v-if="tried && problems.length" class="msg bad">
        <div v-for="p in problems" :key="p">{{ p }}</div>
      </div>
      <p v-if="error" class="msg bad">{{ error }}</p>

      <div class="sticky-foot">
        <button class="btn primary big" type="submit" :disabled="busy">
          {{ busy ? '...' : `حفظ المنتج (${rows.length} قطعة)` }}
        </button>
        <span v-if="openingTotal" class="muted">رصيد افتتاحي: {{ openingTotal }} قطعة</span>
      </div>
    </form>
  </div>
</template>
