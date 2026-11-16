<script setup>
import { onMounted, reactive, ref, watch } from 'vue'
import { dash } from '../api.js'
import { session } from '../../shared/session.js'
import { href } from '../router.js'
import { view, money, toMinor, toDecimal, fail, say, debounced } from '../lib.js'

const search = ref('')
const active = ref('')
const categoryId = ref('')
const categories = ref([])
const products = ref([])
const page = ref(1)
const lastPage = ref(1)
const loading = ref(false)
const error = ref('')

// Per-variant edit state, so a failed save can put the old value back.
const drafts = reactive({})      // variantId -> price text being typed
const rowError = reactive({})    // variantId -> message
const confirming = ref(null)     // variantId awaiting "are you sure" on delete

const LOW = 5
const showCost = session.user.sees_cost

// A newer search must win over a slower older one, so every request is numbered
// and only the latest is allowed to write the list.
let seq = 0

async function load(reset = true) {
    const mine = ++seq
    loading.value = true
    error.value = ''

    if (reset) page.value = 1

    try {
        const res = await dash.products({
            search: search.value.trim(),
            category_id: categoryId.value,
            active: active.value,
            branch_id: view.branchId,
            page: page.value,
        })

        if (mine !== seq) return

        products.value = reset ? res.data : [...products.value, ...res.data]
        lastPage.value = res.meta.last_page
    } catch (e) {
        if (mine === seq) error.value = fail(e)
    } finally {
        if (mine === seq) loading.value = false
    }
}

const reload = debounced(() => load(true))
watch([search], reload)
watch([active, categoryId], () => load(true))

function more() {
    if (loading.value) return
    page.value += 1
    load(false)
}

onMounted(async () => {
    load(true)
    try {
        const { data } = await dash.categories()
        categories.value = data.flatMap((c) => [c, ...(c.children ?? [])])
    } catch (e) { /* the filter is a convenience; the list still works */ }
})

function replaceVariant(updated) {
    for (const p of products.value) {
        const i = p.variants.findIndex((v) => v.id === updated.id)
        // Keep the stock the listing already had: the PATCH response has none.
        if (i !== -1) p.variants[i] = { ...p.variants[i], ...updated, stock: p.variants[i].stock }
    }
}

async function savePrice(variant) {
    const text = drafts[variant.id]
    if (text === undefined) return

    const minor = toMinor(text)
    delete rowError[variant.id]

    if (minor === null) {
        rowError[variant.id] = 'السعر لازم يكون رقم'
        return
    }
    if (minor === toMinor(variant.price)) {
        delete drafts[variant.id]
        return
    }

    try {
        const res = await dash.updateVariant(variant.id, { price: toDecimal(minor) })
        replaceVariant(res.data)
        delete drafts[variant.id]
        say('اتحفظ السعر')
    } catch (e) {
        rowError[variant.id] = fail(e)
    }
}

async function saveCost(variant, text) {
    const minor = toMinor(text)
    delete rowError[variant.id]

    if (text.trim() !== '' && minor === null) {
        rowError[variant.id] = 'التكلفة لازم تكون رقم'
        return
    }

    try {
        const res = await dash.updateVariant(variant.id, { cost: minor === null ? null : toDecimal(minor) })
        replaceVariant(res.data)
        say('اتحفظت التكلفة')
    } catch (e) {
        rowError[variant.id] = fail(e)
    }
}

async function toggleVariant(variant) {
    delete rowError[variant.id]
    try {
        const res = await dash.updateVariant(variant.id, { is_active: !variant.is_active })
        replaceVariant(res.data)
        say(res.data.is_active ? 'رجعت للبيع' : 'اتوقفت عن البيع')
    } catch (e) {
        rowError[variant.id] = fail(e)
    }
}

async function toggleProduct(product) {
    error.value = ''
    try {
        const res = await dash.updateProduct(product.id, { is_active: !product.is_active })
        product.is_active = res.data.is_active
        say(product.is_active ? 'المنتج رجع للبيع' : 'المنتج اتوقف عن البيع')
    } catch (e) {
        error.value = fail(e)
    }
}

async function remove(product, variant) {
    delete rowError[variant.id]
    confirming.value = null

    try {
        await dash.deleteVariant(variant.id)
        product.variants = product.variants.filter((v) => v.id !== variant.id)
        say('اتمسحت القطعة')
    } catch (e) {
        // variant_still_in_stock lands here: the message says to switch it off.
        rowError[variant.id] = fail(e)
    }
}

const qtyClass = (q) => (q < 0 ? 'qty-bad' : q <= LOW ? 'qty-low' : '')
</script>

<template>
  <div class="stack">
    <div class="page-head">
      <h2>المنتجات</h2>
      <a v-if="!session.readOnly" class="btn primary" :href="href('/products/new')">+ منتج جديد</a>
    </div>

    <div class="toolbar">
      <input v-model="search" class="input search" type="search" placeholder="ابحث بالاسم أو الكود" aria-label="بحث">
      <select v-model="categoryId" class="input" aria-label="التصنيف">
        <option value="">كل التصنيفات</option>
        <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option>
      </select>
      <select v-model="active" class="input" aria-label="الحالة">
        <option value="">الكل</option>
        <option value="1">شغّال</option>
        <option value="0">موقوف</option>
      </select>
    </div>

    <p v-if="error" class="msg bad">{{ error }}</p>
    <p v-if="!loading && !products.length && !error" class="center muted">مفيش منتجات.</p>

    <article v-for="p in products" :key="p.id" class="card stack" :style="{ opacity: p.is_active ? 1 : 0.7 }">
      <div class="row wrap">
        <strong class="grow" style="font-size: 17px">{{ p.name }}</strong>
        <span v-if="p.category?.name" class="badge">{{ p.category.name }}</span>
        <span v-if="!p.is_active" class="badge warn">موقوف</span>
        <a class="btn sm" :href="href('/labels', { product: p.id })">استيكرات</a>
        <button v-if="!session.readOnly" class="btn sm" @click="toggleProduct(p)">{{ p.is_active ? 'أوقف المنتج' : 'رجّعه للبيع' }}</button>
      </div>

      <div class="tbl-wrap">
        <table class="tbl stacks">
          <thead>
            <tr>
              <th>القطعة</th>
              <th>الكود</th>
              <th>السعر</th>
              <th v-if="showCost">التكلفة</th>
              <th>الرصيد</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <template v-for="v in p.variants" :key="v.id">
              <tr :class="{ off: !v.is_active }">
                <td class="id">{{ v.label }} <span v-if="!v.is_active" class="badge warn">موقوف</span></td>
                <td data-label="الكود"><span class="mono muted">{{ v.sku }}</span></td>
                <td data-label="السعر">
                  <input
                    class="input cell"
                    :class="{ edited: drafts[v.id] !== undefined, invalid: rowError[v.id] }"
                    inputmode="decimal"
                    :value="drafts[v.id] ?? money(v.price).replace(/,/g, '')"
                    :readonly="session.readOnly"
                    :aria-label="`سعر ${v.label}`"
                    @input="drafts[v.id] = $event.target.value"
                    @change="savePrice(v)"
                    @keydown.enter.prevent="$event.target.blur()"
                    @keydown.esc="delete drafts[v.id]"
                  >
                </td>
                <td v-if="showCost" data-label="التكلفة">
                  <input
                    class="input cell"
                    inputmode="decimal"
                    :value="v.cost == null ? '' : money(v.cost).replace(/,/g, '')"
                    placeholder="—"
                    :readonly="session.readOnly"
                    :aria-label="`تكلفة ${v.label}`"
                    @change="saveCost(v, $event.target.value)"
                    @keydown.enter.prevent="$event.target.blur()"
                  >
                </td>
                <td data-label="الرصيد" class="num">
                  <span v-if="v.stock !== undefined" :class="qtyClass(v.stock)">{{ v.stock }}</span>
                  <span v-else class="muted">—</span>
                </td>
                <td class="actions">
                  <div v-if="!session.readOnly" class="row wrap">
                    <button class="btn sm" @click="toggleVariant(v)">{{ v.is_active ? 'أوقف' : 'رجّع' }}</button>
                    <template v-if="confirming === v.id">
                      <button class="btn sm danger" @click="remove(p, v)">تأكيد المسح</button>
                      <button class="btn sm quiet" @click="confirming = null">لأ</button>
                    </template>
                    <button v-else class="btn sm quiet danger" @click="confirming = v.id">مسح</button>
                  </div>
                </td>
              </tr>
              <tr v-if="rowError[v.id]" class="note">
                <td class="span" :colspan="showCost ? 6 : 5"><p class="msg bad">{{ rowError[v.id] }}</p></td>
              </tr>
            </template>
          </tbody>
        </table>
      </div>
    </article>

    <p v-if="loading" class="center muted">...</p>
    <button v-else-if="page < lastPage" class="btn full" @click="more">تحميل المزيد</button>
  </div>
</template>
