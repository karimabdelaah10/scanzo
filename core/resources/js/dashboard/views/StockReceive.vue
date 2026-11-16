<script setup>
import { computed, reactive, ref } from 'vue'
import StockTabs from '../components/StockTabs.vue'
import CodeEntry from '../components/CodeEntry.vue'
import { dash } from '../api.js'
import { view, branchName, toInt, fail, say } from '../lib.js'

/**
 * Goods in, laid out like the till's cart: scan a code, it becomes a line with
 * a quantity, and one tap posts the whole delivery.
 */
const lines = reactive([])
const note = ref('')
const busy = ref(false)
const error = ref('')
const done = ref(null)
const entry = ref(null)

function add(row) {
    done.value = null
    const line = lines.find((l) => l.variantId === row.variant_id)

    if (line) {
        line.quantity = (toInt(line.quantity) ?? 0) + 1
    } else {
        lines.push({
            variantId: row.variant_id,
            sku: row.sku,
            name: `${row.product_name} · ${row.label}`,
            onHand: row.quantity,
            active: row.is_active,
            quantity: 1,
        })
    }
}

const qty = (l) => toInt(l.quantity)
const valid = (l) => qty(l) !== null && qty(l) >= 1
const total = computed(() => lines.reduce((s, l) => s + (valid(l) ? qty(l) : 0), 0))
const canSave = computed(() => lines.length > 0 && lines.every(valid) && !busy.value)

async function save() {
    if (!canSave.value) return
    busy.value = true
    error.value = ''

    try {
        const res = await dash.receive({
            branch_id: view.branchId,
            items: lines.map((l) => ({ variant_id: l.variantId, quantity: qty(l) })),
            note: note.value.trim() || null,
        })

        done.value = { pieces: total.value, kinds: res.data.length }
        lines.splice(0)
        note.value = ''
        say('اتسجّل الاستلام')
    } catch (e) {
        // The lines stay, so a failed save is a retry and not a re-scan.
        error.value = fail(e)
    } finally {
        busy.value = false
    }
}
</script>

<template>
  <div class="stack">
    <StockTabs />

    <p v-if="done" class="msg ok">
      اتسجّل استلام {{ done.pieces }} قطعة ({{ done.kinds }} صنف) في {{ branchName(view.branchId) }}.
    </p>

    <CodeEntry ref="entry" :branch-id="view.branchId" @pick="add" />

    <div class="card stack">
      <p v-if="!lines.length" class="center muted">امسح كود القطعة اللي وصلت، وكل مسحة بتزوّد واحد.</p>

      <div v-for="(l, i) in lines" :key="l.variantId" class="row">
        <div class="grow">
          <div>{{ l.name }}</div>
          <div class="muted">
            <span class="mono">{{ l.sku }}</span> · الرصيد الحالي {{ l.onHand }}
            <span v-if="!l.active" class="badge warn">موقوف عن البيع</span>
          </div>
        </div>
        <div class="stepper">
          <button class="step" type="button" aria-label="أنقص" :disabled="qty(l) <= 1" @click="l.quantity = Math.max(1, (qty(l) ?? 1) - 1)">−</button>
          <input
            v-model="l.quantity"
            class="input code"
            :class="{ invalid: !valid(l) }"
            style="width: 76px; text-align: center"
            inputmode="numeric"
            :aria-label="`كمية ${l.name}`"
          >
          <button class="step" type="button" aria-label="زوّد" @click="l.quantity = (qty(l) ?? 0) + 1">+</button>
        </div>
        <button class="btn sm quiet danger" type="button" @click="lines.splice(i, 1); entry?.focus()">شيل</button>
      </div>
    </div>

    <div v-if="lines.length" class="stack">
      <div class="field">
        <label for="note">ملاحظة (اختياري) — مثلاً اسم المورد</label>
        <input id="note" v-model="note" class="input" maxlength="255">
      </div>
      <p v-if="error" class="msg bad">{{ error }}</p>
      <div class="sticky-foot">
        <button class="btn primary big" :disabled="!canSave" @click="save">
          {{ busy ? '...' : `تسجيل استلام ${total} قطعة` }}
        </button>
      </div>
    </div>
  </div>
</template>
