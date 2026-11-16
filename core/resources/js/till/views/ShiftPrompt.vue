<script setup>
import { ref } from 'vue'
import { ApiError } from '../../shared/api.js'
import { shopCurrency } from '../../shared/currency.js'
import { newShiftId, openShift, shift, shiftMessage, loadShift } from '../shift.js'

/**
 * Offered once, when work starts and no drawer shift is open. Opening it takes
 * the float right here; closing the window sells as usual. It is not a gate,
 * and putting it away does not hide the fact: the banner on the sell screen
 * stays until a shift is opened.
 */

const emit = defineEmits(['opened'])
const cur = shopCurrency

const float = ref('')
const busy = ref(false)
const error = ref('')
const shiftId = newShiftId()

async function submit() {
    if (busy.value) return

    const value = Number(float.value)

    if (float.value === '' || !Number.isFinite(value) || value < 0) {
        error.value = 'اكتب الفكّة اللي في الدرج بالأرقام (صفر لو فاضي).'
        return
    }

    error.value = ''
    busy.value = true

    try {
        await openShift({ id: shiftId, openingFloat: value })
        emit('opened')
    } catch (e) {
        error.value = shiftMessage(e)
        if (e instanceof ApiError && e.code === 'shift_already_open') await loadShift()
    } finally {
        busy.value = false
    }
}

function dismiss() {
    shift.promptDismissed = true
}
</script>

<template>
  <div
    role="dialog"
    aria-modal="true"
    aria-labelledby="shift-prompt-title"
    style="position: fixed; inset: 0; z-index: 50; background: rgba(17, 24, 39, 0.55); display: flex; align-items: center; justify-content: center; padding: 16px"
    @keydown.esc="dismiss"
  >
    <form class="card stack" style="width: 100%; max-width: 380px" @submit.prevent="submit">
      <h2 id="shift-prompt-title" style="margin: 0">افتح الوردية؟</h2>
      <p class="muted" style="margin: 0">
        {{ shift.stale
          ? 'فيه وردية من يوم قبل كده لسه مفتوحة. لو فتحت الجديدة هتتقفل القديمة من غير عدّ.'
          : 'مفيش وردية مفتوحة على الكاشير ده، فالكاش مش هيتحسب. عدّ الفكّة اللي في الدرج واكتبها.' }}
      </p>

      <div class="field" style="gap: 8px">
        <label for="prompt-float">الفكّة في الدرج ({{ cur() }})</label>
        <input
          id="prompt-float"
          v-model="float"
          class="input code"
          inputmode="decimal"
          autocomplete="off"
          autofocus
          placeholder="0"
        >
      </div>

      <p v-if="error" class="msg bad">{{ error }}</p>

      <button class="btn big" type="submit" :disabled="busy">افتح الوردية</button>
      <button class="btn quiet full" type="button" @click="dismiss">كمّل بدون وردية</button>
    </form>
  </div>
</template>
