<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { dash } from '../api.js'
import { route } from '../router.js'
import { session } from '../../shared/session.js'
import { view, branchName, labelsUrl, TEMPLATES, fail, debounced, toInt } from '../lib.js'

// The print page puts every id in the URL, and nginx refuses long ones, so a
// hand-picked set is capped. Picking one whole product is sent as product_id
// and has no such limit.
const MAX_PICKED = 100
const MAX_LABELS = 2000 // the server's own cap

const search = ref('')
const results = ref([])
const page = ref(1)
const lastPage = ref(1)
const loading = ref(false)
const error = ref('')

const catalog = reactive({})   // productId -> product, every one seen so far
const picked = reactive({})    // variantId -> { productId, name, label, sku, stock }

// The shop's own size; the server already maps unknown stored values to a template.
const template = ref(
    TEMPLATES.some((t) => t.value === session.business?.label_size) ? session.business.label_size : TEMPLATES[0].value,
)
const copies = ref('stock')
const count = ref('1')
const showPrice = ref(true)
const showBarcode = ref(false) // off until the template's own check below allows it

const tpl = computed(() => TEMPLATES.find((t) => t.value === template.value))
watch(template, () => { if (!tpl.value.barcode) showBarcode.value = false })

let seq = 0

function remember(products) {
    for (const p of products) catalog[p.id] = p
}

function pickAll(product, on) {
    for (const v of product.variants) togglePick(product, v, on)
}

function togglePick(product, v, on = !picked[v.id]) {
    if (on) {
        picked[v.id] = {
            productId: product.id,
            name: product.name,
            label: v.label,
            sku: v.sku,
            stock: v.stock ?? 0,
        }
    } else {
        delete picked[v.id]
    }
}

const isAll = (product) => product.variants.length > 0 && product.variants.every((v) => picked[v.id])

async function load(reset = true) {
    const mine = ++seq
    loading.value = true
    error.value = ''
    if (reset) page.value = 1

    try {
        const res = await dash.products({
            search: search.value.trim(),
            branch_id: view.branchId,
            page: page.value,
        })
        if (mine !== seq) return
        remember(res.data)
        results.value = reset ? res.data : [...results.value, ...res.data]
        lastPage.value = res.meta.last_page
    } catch (e) {
        if (mine === seq) error.value = fail(e)
    } finally {
        if (mine === seq) loading.value = false
    }
}

const reload = debounced(() => load(true))
watch(search, reload)

onMounted(async () => {
    load(true)

    // Arriving from a product row: that product is already chosen.
    if (route.value.query.product) {
        try {
            const { data } = await dash.product(route.value.query.product, view.branchId)
            remember([data])
            pickAll(data, true)
        } catch (e) {
            error.value = fail(e)
        }
    }
})

function more() {
    if (loading.value) return
    page.value += 1
    load(false)
}

const list = computed(() => Object.entries(picked).map(([id, v]) => ({ id, ...v })))

/** Exactly one product, every one of its variants: sent as product_id. */
const wholeProduct = computed(() => {
    const ids = new Set(list.value.map((v) => v.productId))
    if (ids.size !== 1) return null

    const product = catalog[[...ids][0]]
    return product && product.variants.length === list.value.length ? product : null
})

const labelTotal = computed(() => {
    if (copies.value === 'stock') return list.value.reduce((s, v) => s + Math.max(0, v.stock), 0)
    if (copies.value === 'one') return list.value.length
    return list.value.length * Math.max(0, toInt(count.value) ?? 0)
})

const problem = computed(() => {
    if (!list.value.length) return 'اختار منتج أو قطع الأول.'
    if (!wholeProduct.value && list.value.length > MAX_PICKED) return `اختارت ${list.value.length} قطعة. الحد ${MAX_PICKED} لما تختار قطع بإيديك — أو اختار منتج واحد كامل.`
    if (copies.value === 'fixed' && !(toInt(count.value) >= 1 && toInt(count.value) <= 500)) return 'العدد لازم يكون من 1 لـ 500.'
    if (labelTotal.value === 0) return 'مفيش رصيد في الفرع للقطع دي، فمفيش استيكرات. اختار "واحد لكل قطعة" أو "عدد معيّن".'
    if (labelTotal.value > MAX_LABELS) return `${labelTotal.value} استيكر كتير. الحد ${MAX_LABELS} في المرة.`
    return ''
})

const urlFor = (kind, download = false) => labelsUrl({
    productId: wholeProduct.value?.id,
    variantIds: wholeProduct.value ? [] : list.value.map((v) => v.id),
    branchId: view.branchId,
    copies: copies.value,
    count: toInt(count.value),
    template: template.value,
    showPrice: showPrice.value,
    showBarcode: showBarcode.value,
    kind,
    download,
})

const url = computed(() => problem.value ? null : urlFor('print'))
const pdfUrl = computed(() => problem.value ? null : urlFor('pdf', true))
const calibrationUrl = computed(() => `/labels/calibration?template=${template.value}`)

// Sharing a file needs HTTPS and a browser that supports it (mostly phones).
// Where it does not, the button is simply not there: the download is enough.
const canShare = typeof navigator !== 'undefined'
    && typeof navigator.canShare === 'function'
    && typeof File === 'function'
    && navigator.canShare({ files: [new File([''], 'labels.pdf', { type: 'application/pdf' })] })

const sharing = ref(false)
const shareError = ref('')

async function share() {
    sharing.value = true
    shareError.value = ''

    try {
        const res = await fetch(pdfUrl.value, { credentials: 'same-origin' })
        const type = res.headers.get('content-type') ?? ''
        if (!res.ok || !type.includes('application/pdf')) throw new Error('pdf')

        const file = new File([await res.blob()], `labels-${template.value}.pdf`, { type: 'application/pdf' })
        await navigator.share({ files: [file], title: 'استيكرات' })
    } catch (e) {
        // Closing the share sheet is not a failure.
        if (e?.name !== 'AbortError') shareError.value = 'معرفتش أجهّز الملف للمشاركة. نزّل الـ PDF وابعته من الملفات.'
    } finally {
        sharing.value = false
    }
}

function clearAll() {
    Object.keys(picked).forEach((k) => delete picked[k])
}
</script>

<template>
  <div class="stack">
    <div class="page-head">
      <h2>الاستيكرات</h2>
    </div>

    <div class="two" style="align-items: start">
      <!-- 1. what to print -->
      <section class="stack">
        <input v-model="search" class="input" type="search" placeholder="ابحث عن منتج بالاسم أو الكود" aria-label="بحث">
        <p v-if="error" class="msg bad">{{ error }}</p>

        <div v-for="p in results" :key="p.id" class="card tight stack">
          <label class="choice" :class="{ on: isAll(p) }">
            <input type="checkbox" :checked="isAll(p)" @change="pickAll(p, $event.target.checked)">
            <strong>{{ p.name }}</strong>
            <span class="muted">كل القطع ({{ p.variants.length }})</span>
          </label>
          <label v-for="v in p.variants" :key="v.id" class="choice" :class="{ on: picked[v.id] }" style="margin-inline-start: 18px">
            <input type="checkbox" :checked="!!picked[v.id]" @change="togglePick(p, v, $event.target.checked)">
            <span class="grow">{{ v.label }}</span>
            <span class="mono muted">{{ v.sku }}</span>
            <span class="muted">رصيد {{ v.stock ?? 0 }}</span>
          </label>
        </div>

        <p v-if="!loading && !results.length && !error" class="center muted">مفيش منتجات.</p>
        <p v-if="loading" class="center muted">...</p>
        <button v-else-if="page < lastPage" class="btn full" @click="more">تحميل المزيد</button>
      </section>

      <!-- 2. how to print it -->
      <section class="card stack" style="position: sticky; top: 76px">
        <div class="field">
          <label>القالب</label>
          <label v-for="t in TEMPLATES" :key="t.value" class="choice" :class="{ on: template === t.value }">
            <input v-model="template" type="radio" name="tpl" :value="t.value">{{ t.label }}
          </label>
        </div>

        <div class="field">
          <label>عدد الاستيكرات لكل قطعة</label>
          <label class="choice" :class="{ on: copies === 'stock' }">
            <input v-model="copies" type="radio" name="copies" value="stock">
            بعدد المخزون في {{ branchName(view.branchId) }}
          </label>
          <label class="choice" :class="{ on: copies === 'one' }">
            <input v-model="copies" type="radio" name="copies" value="one">واحد لكل قطعة
          </label>
          <label class="choice" :class="{ on: copies === 'fixed' }">
            <input v-model="copies" type="radio" name="copies" value="fixed">عدد معيّن
            <input
              v-if="copies === 'fixed'"
              v-model="count"
              class="input code"
              style="width: 80px; min-height: 36px"
              inputmode="numeric"
              aria-label="العدد"
              @click.prevent
            >
          </label>
        </div>

        <div class="row wrap">
          <label class="choice"><input v-model="showPrice" type="checkbox">السعر على الاستيكر</label>
          <label class="choice" :class="{ on: showBarcode }" :style="{ opacity: tpl.barcode ? 1 : 0.5 }">
            <input v-model="showBarcode" type="checkbox" :disabled="!tpl.barcode">باركود
          </label>
        </div>
        <p v-if="!tpl.barcode" class="muted">الرول الصغير (40×30) مفيهوش مكان للباركود.</p>

        <div v-if="list.length" class="stack">
          <div class="row"><strong class="grow">المختار ({{ list.length }})</strong>
            <button class="btn sm quiet" @click="clearAll">امسح الكل</button></div>
          <div style="max-height: 180px; overflow-y: auto" class="stack">
            <div v-for="v in list" :key="v.id" class="row muted">
              <span class="grow">{{ v.name }} · {{ v.label }}</span>
              <button class="btn sm quiet" :aria-label="`شيل ${v.label}`" @click="delete picked[v.id]">×</button>
            </div>
          </div>
        </div>

        <p v-if="problem && list.length" class="msg warn">{{ problem }}</p>
        <p v-else-if="url" class="msg ok">هيطلع {{ labelTotal }} استيكر.</p>

        <a v-if="pdfUrl" class="btn primary big" :href="pdfUrl">تحميل PDF</a>
        <button v-else class="btn primary big" disabled>تحميل PDF</button>

        <button v-if="canShare" class="btn big" :disabled="!pdfUrl || sharing" @click="share">
          {{ sharing ? '...' : 'مشاركة لتطبيق الطابعة' }}
        </button>
        <p v-if="shareError" class="msg bad">{{ shareError }}</p>

        <a v-if="url" class="btn big" :href="url" target="_blank" rel="noopener">طباعة من المتصفح</a>
        <button v-else class="btn big" disabled>طباعة من المتصفح</button>

        <p class="muted">
          <a :href="calibrationUrl" target="_blank" rel="noopener"><strong>اطبع ده الأول وقيسه</strong></a>
          — خط 50 مم يكشف لو الطابعة بتكبّر أو بتصغّر الاستيكر.
        </p>
      </section>
    </div>
  </div>
</template>
