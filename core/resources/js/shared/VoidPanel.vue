<script setup>
import { ref } from 'vue'
import { voidInvoice, voidMessage } from './api.js'

/**
 * The "cancel this invoice" control. It asks for the reason, which the server
 * requires, and confirms before anything is sent: a cancellation puts stock
 * back and cannot be undone.
 *
 * It shows only when the server said `can_void` for this user and invoice. That
 * is a courtesy, not the check: the server decides again when this is pressed,
 * and its refusal is shown here in plain words.
 *
 * It holds no layout of its own beyond one block, so the till (phone) and the
 * dashboard (desktop) can put it wherever suits them.
 */
const props = defineProps({
    invoice: { type: Object, required: true },
})

const emit = defineEmits(['voided'])

const asking = ref(false)
const reason = ref('')
const busy = ref(false)
const error = ref('')

function open() {
    asking.value = true
    error.value = ''
}

function close() {
    asking.value = false
    reason.value = ''
    error.value = ''
}

async function confirm() {
    if (busy.value) return

    if (!reason.value.trim()) {
        error.value = 'اكتب سبب الإلغاء.'
        return
    }

    busy.value = true
    error.value = ''

    try {
        const res = await voidInvoice(props.invoice.id, reason.value.trim())
        close()
        emit('voided', res.data)
    } catch (e) {
        error.value = voidMessage(e)
    } finally {
        busy.value = false
    }
}
</script>

<template>
  <div v-if="invoice.can_void" class="stack void-panel">
    <button v-if="!asking" type="button" class="btn danger full" style="flex: none" @click="open">
      إلغاء الفاتورة
    </button>

    <form v-else class="card stack" @submit.prevent="confirm">
      <label for="void-reason"><strong>سبب الإلغاء</strong></label>
      <textarea
        id="void-reason"
        v-model="reason"
        class="input"
        rows="2"
        maxlength="500"
        placeholder="مثلًا: القطعة اتباعت بالغلط"
        :disabled="busy"
      />
      <p class="muted">القطع هترجع للمخزون، والفاتورة هتفضل ظاهرة بحالة «ملغية».</p>
      <p v-if="error" class="msg bad">{{ error }}</p>
      <div class="row" style="gap: 8px">
        <button type="submit" class="btn danger grow" :disabled="busy">
          {{ busy ? '...' : 'تأكيد الإلغاء' }}
        </button>
        <button type="button" class="btn quiet" :disabled="busy" @click="close">تراجع</button>
      </div>
    </form>
  </div>
</template>
