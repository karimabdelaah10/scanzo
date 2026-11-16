<script setup>
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { session } from '../../shared/session.js'
import { till, ApiError } from '../../shared/api.js'
import { shopCurrency } from '../../shared/currency.js'

/**
 * Find a piece by what it is called, for when its label is gone.
 *
 * A way out, not the main road: it is a sheet that opens over the selling
 * screen and closes again, and the camera stays where it was underneath.
 *
 * `remainingOf(variant)` is how many can still be added — the branch's stock
 * less what is already in the cart — so a row that cannot be sold says so
 * instead of failing after the tap.
 */

const props = defineProps({
    remainingOf: { type: Function, required: true },
})

const emit = defineEmits(['pick', 'close'])

const cur = shopCurrency

const MIN_CHARS = 2
const DEBOUNCE_MS = 300

const input = ref(null)
const query = ref('')
const rows = ref([])
const page = ref(1)
const hasMore = ref(false)
const loading = ref(false)
const searched = ref(false)
const error = ref('')

let timer = null
// Only the latest request may write: a slow answer for "قم" must not replace
// the one for "قميص".
let seq = 0

async function run(reset = true) {
    const word = query.value.trim()
    const mine = ++seq

    if (word.length < MIN_CHARS) {
        rows.value = []
        hasMore.value = false
        searched.value = false
        loading.value = false
        error.value = ''
        return
    }

    if (reset) page.value = 1

    loading.value = true
    error.value = ''

    try {
        const res = await till.searchVariants(word, session.branchId, page.value)
        if (mine !== seq) return

        rows.value = reset ? res.data : [...rows.value, ...res.data]
        hasMore.value = res.meta.has_more
        searched.value = true
    } catch (e) {
        if (mine !== seq) return
        error.value = e instanceof ApiError ? e.message : 'حصل خطأ'
    } finally {
        if (mine === seq) loading.value = false
    }
}

function more() {
    if (loading.value || !hasMore.value) return
    page.value++
    run(false)
}

watch(query, () => {
    clearTimeout(timer)
    timer = setTimeout(() => run(true), DEBOUNCE_MS)
})

function onKey(event) {
    if (event.key === 'Escape') emit('close')
}

onMounted(() => {
    input.value?.focus()
    window.addEventListener('keydown', onKey)
})

onBeforeUnmount(() => {
    clearTimeout(timer)
    window.removeEventListener('keydown', onKey)
})
</script>

<template>
  <div class="scrim" @click.self="emit('close')">
    <div class="sheet" role="dialog" aria-modal="true" aria-label="بحث بالاسم" style="max-height: 88%">
      <span class="grab" aria-hidden="true"></span>

      <div class="row">
        <input
          ref="input"
          v-model="query"
          class="input grow"
          type="search"
          placeholder="اسم القطعة أو المقاس أو اللون"
          aria-label="بحث بالاسم أو المقاس أو اللون"
          autocomplete="off"
          enterkeyhint="search"
          @keydown.enter.prevent="run(true)"
        >
        <button class="btn quiet" style="min-height: 44px; padding: 0 10px" @click="emit('close')">
          إغلاق
        </button>
      </div>

      <p v-if="error" class="msg bad">{{ error }}</p>

      <p v-else-if="query.trim().length < MIN_CHARS" class="muted" style="margin: 4px 0">
        اكتب حرفين على الأقل. ممكن تكتب أكتر من كلمة، مثلًا «قميص أزرق».
      </p>

      <p v-else-if="loading && !rows.length" class="muted" style="margin: 4px 0">...</p>

      <p v-else-if="searched && !rows.length" class="muted" style="margin: 4px 0">
        مفيش نتايج لـ «{{ query.trim() }}».
      </p>

      <ul v-if="rows.length" class="results">
        <li v-for="row in rows" :key="row.id">
          <button
            class="result"
            :disabled="props.remainingOf(row) <= 0"
            @click="emit('pick', row)"
          >
            <span class="grow" style="text-align: start">
              <strong>{{ row.product_name }}</strong>
              <span v-if="row.label" class="muted" style="display: block">{{ row.label }}</span>
              <span class="mono muted">{{ row.sku }}</span>
            </span>
            <span style="text-align: end; flex: none">
              <strong style="font-variant-numeric: tabular-nums">{{ row.price }} {{ cur() }}</strong>
              <span class="muted" style="display: block">
                {{ props.remainingOf(row) > 0 ? `متاح ${props.remainingOf(row)}` : 'مفيش متاح' }}
              </span>
            </span>
          </button>
        </li>
      </ul>

      <button v-if="hasMore" class="btn full" :disabled="loading" @click="more">
        {{ loading ? '...' : 'عرض المزيد' }}
      </button>
    </div>
  </div>
</template>

<style scoped>
.results { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 6px; overflow-y: auto; }
.result {
  width: 100%; min-height: 60px; padding: 8px 12px; display: flex; align-items: center; gap: 12px;
  background: var(--bg); border: 1px solid var(--line); border-radius: var(--radius);
}
.result:disabled { opacity: 0.5; }
</style>
