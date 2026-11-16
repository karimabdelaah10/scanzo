<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import StockTabs from '../components/StockTabs.vue'
import CodeEntry from '../components/CodeEntry.vue'
import { dash } from '../api.js'
import { href } from '../router.js'
import { view, branchName, toInt, fail, say } from '../lib.js'

/**
 * Stock moving between branches. The source is the branch the shell is looking
 * at, so the balances shown while picking are the source's own. The target list
 * comes from the server, because a manager's session lists only their branch
 * but they may send to any.
 */
const lines = reactive([])
const toBranchId = ref('')
const note = ref('')
const branches = ref([])
const busy = ref(false)
const error = ref('')
const done = ref(null)
const entry = ref(null)

const name = (id) => branches.value.find((b) => b.id === id)?.name ?? branchName(id) ?? ''
const targets = computed(() => branches.value.filter((b) => b.id !== view.branchId))

// Only for the list of branches to send to; the transfers are on their own screen.
async function loadBranches() {
    try {
        const res = await dash.transfers({ status: 'in_transit' })
        branches.value = res.meta.branches
    } catch (e) {
        error.value = fail(e)
    }
}

onMounted(loadBranches)

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
            quantity: 1,
        })
    }
}

const qty = (l) => toInt(l.quantity)
// More than the source holds is refused here as well as by the server.
const valid = (l) => qty(l) !== null && qty(l) >= 1 && qty(l) <= l.onHand
const total = computed(() => lines.reduce((s, l) => s + (valid(l) ? qty(l) : 0), 0))
const canSave = computed(() => lines.length > 0 && lines.every(valid) && !!toBranchId.value && !busy.value)

async function save() {
    if (!canSave.value) return
    busy.value = true
    error.value = ''

    try {
        await dash.transfer({
            from_branch_id: view.branchId,
            to_branch_id: toBranchId.value,
            items: lines.map((l) => ({ variant_id: l.variantId, quantity: qty(l) })),
            note: note.value.trim() || null,
        })

        done.value = { pieces: total.value, kinds: lines.length, to: name(toBranchId.value) }
        lines.splice(0)
        note.value = ''
        say('اتبعت التحويل وهو في الطريق')
    } catch (e) {
        // The lines stay, so a refused transfer is a fix and retry, not a re-scan.
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
      اتبعت {{ done.pieces }} قطعة ({{ done.kinds }} صنف) من {{ branchName(view.branchId) }} إلى {{ done.to }}.
      القطع في الطريق لحد ما {{ done.to }} يأكد الاستلام.
    </p>

    <div class="field">
      <label for="to">التحويل إلى فرع</label>
      <select id="to" v-model="toBranchId" class="input">
        <option value="" disabled>اختار الفرع</option>
        <option v-for="b in targets" :key="b.id" :value="b.id">{{ b.name }}</option>
      </select>
    </div>

    <CodeEntry ref="entry" :branch-id="view.branchId" @pick="add" />

    <div class="card stack">
      <p v-if="!lines.length" class="center muted">
        امسح كود القطعة اللي هتتحوّل من {{ branchName(view.branchId) }}. كل مسحة بتزوّد واحد.
      </p>

      <div v-for="(l, i) in lines" :key="l.variantId" class="row">
        <div class="grow">
          <div>{{ l.name }}</div>
          <div class="muted">
            <span class="mono">{{ l.sku }}</span> · المتاح في {{ branchName(view.branchId) }}: {{ l.onHand }}
            <span v-if="qty(l) > l.onHand" class="badge warn">أكتر من المتاح</span>
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
        <label for="note">ملاحظة (اختياري)</label>
        <input id="note" v-model="note" class="input" maxlength="255">
      </div>
      <p v-if="error" class="msg bad">{{ error }}</p>
      <div class="sticky-foot">
        <button class="btn primary big" :disabled="!canSave" @click="save">
          {{ busy ? '...' : `تحويل ${total} قطعة` }}
        </button>
      </div>
    </div>
    <p v-else-if="error" class="msg bad">{{ error }}</p>

    <p class="muted">
      بعد الإرسال القطع بتبقى «في الطريق» ومبتدخلش الفرع التاني غير لما يأكد الاستلام.
      <a :href="href('/stock/transfers')">متابعة التحويلات</a>
    </p>
  </div>
</template>
