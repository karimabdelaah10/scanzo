<script setup>
import { computed, reactive, ref } from 'vue'
import StockTabs from '../components/StockTabs.vue'
import CodeEntry from '../components/CodeEntry.vue'
import { dash } from '../api.js'
import { view, branchName, toInt, fail, say } from '../lib.js'

/**
 * A stock take. The shop sends what it COUNTED, never a difference: that is the
 * number the person holding the pieces knows. The answer says what the system
 * believed, and the gap is the whole point of the screen.
 */
const lines = reactive([])
const note = ref('')
const busy = ref(false)
const error = ref('')
const result = ref(null)
const entry = ref(null)

function add(row) {
    result.value = null
    const line = lines.find((l) => l.variantId === row.variant_id)

    // Counting by scanning: each scan is one more piece on the shelf.
    if (line) {
        line.counted = String((toInt(line.counted) ?? 0) + 1)
    } else {
        lines.push({
            variantId: row.variant_id,
            sku: row.sku,
            name: `${row.product_name} · ${row.label}`,
            system: row.quantity,
            counted: '1',
        })
    }
}

const num = (l) => toInt(l.counted)
const valid = (l) => num(l) !== null && num(l) >= 0
const canSave = computed(() => lines.length > 0 && lines.every(valid) && !busy.value)

async function save() {
    if (!canSave.value) return
    busy.value = true
    error.value = ''

    const names = Object.fromEntries(lines.map((l) => [l.variantId, { name: l.name, sku: l.sku }]))

    try {
        const res = await dash.adjust({
            branch_id: view.branchId,
            items: lines.map((l) => ({ variant_id: l.variantId, counted: num(l) })),
            note: note.value.trim() || null,
        })

        result.value = {
            rows: res.data.map((r) => ({ ...r, ...names[r.variant_id] })),
            corrected: res.meta.corrected,
            unchanged: res.meta.unchanged,
        }
        lines.splice(0)
        note.value = ''
        say('اتسجّل الجرد')
    } catch (e) {
        error.value = fail(e)
    } finally {
        busy.value = false
    }
}

const sign = (n) => (n > 0 ? `+${n}` : String(n))
const diffClass = (n) => (n > 0 ? 'plus' : n < 0 ? 'minus' : 'zero')
</script>

<template>
  <div class="stack">
    <StockTabs />

    <!-- The outcome comes first: it is what the person came here to learn. -->
    <div v-if="result" class="card stack">
      <p class="msg" :class="result.corrected ? 'warn' : 'ok'">
        <template v-if="result.corrected">اتصحّح {{ result.corrected }} صنف<span v-if="result.unchanged"> ومطابق {{ result.unchanged }}</span>.</template>
        <template v-else>كل الأرصدة مطابقة. محدش اتغيّر.</template>
      </p>

      <div class="tbl-wrap">
        <table class="tbl stacks">
          <thead>
            <tr><th>القطعة</th><th>السيستم كان فاكر</th><th>اتعدّ</th><th>الفرق</th></tr>
          </thead>
          <tbody>
            <tr v-for="r in [...result.rows].sort((a, b) => Math.abs(b.difference) - Math.abs(a.difference))" :key="r.variant_id">
              <td class="id">{{ r.name }}<br><span class="mono muted">{{ r.sku }}</span></td>
              <td data-label="السيستم كان فاكر" class="num">{{ r.was }}</td>
              <td data-label="اتعدّ" class="num"><strong>{{ r.counted }}</strong></td>
              <td data-label="الفرق"><span class="diff" :class="diffClass(r.difference)">{{ sign(r.difference) }}</span></td>
            </tr>
          </tbody>
        </table>
      </div>
      <button class="btn" @click="result = null">جرد جديد</button>
    </div>

    <template v-else>
      <p class="muted">
        امسح كل قطعة بتعدّها (كل مسحة = واحد) أو اكتب العدد. الجرد بيصحّح القطع اللي في القايمة بس في {{ branchName(view.branchId) }}.
      </p>

      <CodeEntry ref="entry" :branch-id="view.branchId" @pick="add" />

      <div class="card stack">
        <p v-if="!lines.length" class="center muted">لسه مفيش قطع.</p>

        <div v-for="(l, i) in lines" :key="l.variantId" class="row">
          <div class="grow">
            <div>{{ l.name }}</div>
            <div class="muted"><span class="mono">{{ l.sku }}</span> · السيستم: {{ l.system }}</div>
          </div>
          <label class="row muted">اتعدّ
            <input
              v-model="l.counted"
              class="input code"
              :class="{ invalid: !valid(l) }"
              style="width: 84px; text-align: center"
              inputmode="numeric"
              :aria-label="`العدد الفعلي ${l.name}`"
            >
          </label>
          <button class="btn sm quiet danger" type="button" @click="lines.splice(i, 1); entry?.focus()">شيل</button>
        </div>
      </div>

      <div v-if="lines.length" class="stack">
        <div class="field">
          <label for="cnote">ملاحظة (اختياري)</label>
          <input id="cnote" v-model="note" class="input" maxlength="255" placeholder="جرد">
        </div>
        <p v-if="error" class="msg bad">{{ error }}</p>
        <div class="sticky-foot">
          <button class="btn primary big" :disabled="!canSave" @click="save">
            {{ busy ? '...' : `تسجيل الجرد (${lines.length} صنف)` }}
          </button>
        </div>
      </div>
    </template>
  </div>
</template>
