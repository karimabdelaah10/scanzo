<script setup>
import { ref } from 'vue'
import { findByCode } from '../api.js'
import { fail } from '../lib.js'

/**
 * One box for a scanned or typed code. A USB scanner types the code and presses
 * Enter, so Enter is the whole interaction. An exact SKU is taken straight
 * away; a partial text that matches several items shows them to choose from.
 */
const props = defineProps({
    branchId: { type: String, required: true },
    placeholder: { type: String, default: 'امسح الكود أو اكتبه واضغط Enter' },
})
const emit = defineEmits(['pick'])

const code = ref('')
const choices = ref([])
const error = ref('')
const busy = ref(false)
const input = ref(null)

async function submit() {
    const text = code.value.trim()
    if (!text || busy.value) return

    busy.value = true
    error.value = ''
    choices.value = []

    try {
        const rows = await findByCode(text, props.branchId)

        if (rows.length === 0) {
            error.value = `مفيش قطعة بالكود "${text}".`
        } else if (rows.length === 1) {
            emit('pick', rows[0])
            code.value = ''
        } else {
            choices.value = rows
        }
    } catch (e) {
        error.value = fail(e)
    } finally {
        busy.value = false
        input.value?.focus()
    }
}

function choose(row) {
    emit('pick', row)
    choices.value = []
    code.value = ''
    input.value?.focus()
}

defineExpose({ focus: () => input.value?.focus() })
</script>

<template>
  <div class="stack">
    <form class="row" @submit.prevent="submit">
      <input
        ref="input"
        v-model="code"
        class="input code grow"
        :placeholder="placeholder"
        autocomplete="off"
        autofocus
        aria-label="الكود"
      >
      <button class="btn primary" type="submit" :disabled="busy || !code.trim()">إضافة</button>
    </form>

    <p v-if="error" class="msg bad">{{ error }}</p>

    <div v-if="choices.length" class="card tight stack">
      <span class="muted">أكتر من قطعة مطابقة — اختار:</span>
      <button
        v-for="row in choices"
        :key="row.variant_id"
        type="button"
        class="btn sm"
        style="justify-content: space-between"
        @click="choose(row)"
      >
        <span>{{ row.product_name }} · {{ row.label }}</span>
        <span class="mono muted">{{ row.sku }}</span>
      </button>
    </div>
  </div>
</template>
