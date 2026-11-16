<script setup>
import { computed, ref } from 'vue'
import { ApiError } from '../../shared/api.js'
import { shopCurrency } from '../../shared/currency.js'
import { money, toMinor } from '../cart.js'
import { closeShift, loadShift, shift, shiftMessage } from '../shift.js'

/**
 * Closing the drawer. Two steps, in this order on purpose:
 *
 *   1. the seller types what they COUNTED — nothing else is on the screen, in
 *      particular not what the system expects;
 *   2. only the server's answer to that count shows the expected figure and
 *      the difference.
 *
 * If the expected figure were visible first it would be typed back in as the
 * count, the difference would always be zero and the screen would be pointless.
 *
 * A count cannot be edited afterwards, so there is one confirmation before it
 * is sent.
 */

const emit = defineEmits(['back', 'done'])
const cur = shopCurrency

const counted = ref('')
const note = ref('')
const confirming = ref(false)
const busy = ref(false)
const error = ref('')
const result = ref(null)

const countedValue = computed(() => Number(counted.value))
const valid = computed(() => counted.value !== '' && Number.isFinite(countedValue.value) && countedValue.value >= 0)

function review() {
    if (!valid.value) {
        error.value = 'اكتب المبلغ اللي عدّيته في الدرج بالأرقام (صفر لو فاضي).'
        return
    }

    error.value = ''
    confirming.value = true
}

async function send() {
    if (busy.value) return

    busy.value = true
    error.value = ''

    try {
        result.value = await closeShift({ counted: countedValue.value, note: note.value.trim() })
    } catch (e) {
        confirming.value = false
        error.value = shiftMessage(e)

        // It was closed in the meantime (by a manager, or a retry): show what is true now.
        if (e instanceof ApiError && e.code === 'shift_already_closed') await loadShift()
    } finally {
        busy.value = false
    }
}

const differenceMinor = computed(() => toMinor(result.value?.difference ?? 0))

const verdict = computed(() => {
    if (differenceMinor.value < 0) return { text: 'عجز', css: 'bad' }
    if (differenceMinor.value > 0) return { text: 'زيادة', css: 'warn' }
    return { text: 'مظبوط', css: 'ok' }
})
</script>

<template>
  <div class="screen">
    <div class="topbar">
      <button v-if="!result" class="icon-btn ghost" aria-label="رجوع" @click="emit('back')">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <path d="M9 6l6 6-6 6" />
        </svg>
      </button>
      <h1>{{ result ? 'تم قفل الوردية' : 'قفل الوردية' }}</h1>
    </div>

    <!-- The count has been sent: now, and only now, the expected figure. -->
    <template v-if="result">
      <p class="msg" :class="verdict.css" style="font-size: 18px; font-weight: 700; text-align: center">
        {{ verdict.text }}
        <template v-if="differenceMinor !== 0"> {{ money(Math.abs(differenceMinor)) }} {{ cur() }}</template>
      </p>

      <div class="card" style="display: flex; flex-direction: column; gap: 8px">
        <div class="row"><span class="grow muted">المتوقع</span><strong class="code">{{ money(toMinor(result.expected_cash)) }}</strong></div>
        <div class="row"><span class="grow muted">اللي عدّيته</span><strong class="code">{{ money(toMinor(result.counted_cash)) }}</strong></div>
        <div class="row"><span class="grow muted">الفرق</span><strong class="code">{{ differenceMinor > 0 ? '+' : differenceMinor < 0 ? '−' : '' }}{{ money(Math.abs(differenceMinor)) }}</strong></div>
      </div>

      <button class="btn big" @click="emit('done')">تمام</button>
    </template>

    <template v-else>
      <p class="muted" style="margin: 0">
        عدّ كل الفلوس اللي في الدرج واكتب المبلغ. النظام هيقارنه بعد كده.
      </p>

      <form class="field" style="gap: 8px" @submit.prevent="confirming ? send() : review()">
        <label for="counted">اللي في الدرج فعلاً ({{ cur() }})</label>
        <input
          id="counted"
          v-model="counted"
          class="input code"
          inputmode="decimal"
          autocomplete="off"
          autofocus
          placeholder="0"
          :disabled="confirming"
        >

        <label for="shift-note">ملاحظة (اختياري — لو فيه عجز اشرحه)</label>
        <textarea id="shift-note" v-model="note" class="input" rows="2" maxlength="500" :disabled="confirming" />

        <p v-if="error" class="msg bad">{{ error }}</p>

        <p v-if="confirming" class="msg warn">
          هتقفل الوردية بعدّة <strong>{{ money(toMinor(countedValue)) }} {{ cur() }}</strong>. العدّة مش بتتعدّل بعد كده.
        </p>

        <div class="row">
          <button v-if="confirming" class="btn quiet grow" type="button" :disabled="busy" @click="confirming = false">
            عدّل
          </button>
          <button class="btn big grow" type="submit" :disabled="busy || !shift.current">
            {{ confirming ? 'أكّد وقفّل' : 'قفّل الوردية' }}
          </button>
        </div>
      </form>
    </template>
  </div>
</template>
