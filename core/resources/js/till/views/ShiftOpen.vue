<script setup>
import { ref } from 'vue'
import { ApiError } from '../../shared/api.js'
import { session } from '../../shared/session.js'
import { shopCurrency } from '../../shared/currency.js'
import { newShiftId, openShift, shift, shiftMessage, loadShift } from '../shift.js'

/**
 * Opening the drawer: the change that is in it right now.
 *
 * Zero is a real answer, so the field starts empty and must be filled in,
 * rather than defaulting to 0 and being tapped through.
 */

const emit = defineEmits(['back', 'opened'])
const cur = shopCurrency

const float = ref('')
const busy = ref(false)
const error = ref('')

// One id for this form, so a double tap or a retry cannot open two shifts.
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

        // Someone else got there first: show what is true now.
        if (e instanceof ApiError && e.code === 'shift_already_open') await loadShift()
    } finally {
        busy.value = false
    }
}
</script>

<template>
  <div class="screen">
    <div class="topbar">
      <button class="icon-btn ghost" aria-label="رجوع" @click="emit('back')">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <path d="M9 6l6 6-6 6" />
        </svg>
      </button>
      <h1>فتح الوردية</h1>
    </div>

    <p class="muted" style="margin: 0">
      الكاشير {{ session.device?.code }}. عدّ الفلوس اللي في الدرج دلوقتي واكتبها.
    </p>

    <p v-if="shift.stale" class="msg warn">
      فيه وردية من يوم قبل كده لسه مفتوحة على الكاشير ده. هتتقفل تلقائياً
      <strong>من غير عدّ</strong> أول ما تفتح الجديدة، ومدير الفرع هيشوف إنها اتقفلت كده.
    </p>

    <p v-else-if="shift.current" class="msg warn">
      الكاشير ده عليه وردية مفتوحة من {{ shift.current.opened_by ?? 'زميل' }}. لازم تتقفل الأول.
    </p>

    <form class="field" style="gap: 8px" @submit.prevent="submit">
      <label for="opening-float">الفكّة في الدرج ({{ cur() }})</label>
      <input
        id="opening-float"
        v-model="float"
        class="input code"
        inputmode="decimal"
        autocomplete="off"
        autofocus
        placeholder="0"
      >

      <p v-if="error" class="msg bad">{{ error }}</p>

      <button class="btn big" type="submit" :disabled="busy || (shift.current && !shift.stale)">
        افتح الوردية
      </button>
    </form>
  </div>
</template>
