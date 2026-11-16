<script setup>
import { ref } from 'vue'
import Scanner from '../components/Scanner.vue'
import { session } from '../../shared/session.js'
import { till, ApiError } from '../../shared/api.js'
import { startReturn } from '../returns.js'

/**
 * Finding the sale a return is against.
 *
 * Every receipt carries a QR of its invoice number, so the same gesture that
 * sells starts a return. Typing it stays for a receipt that was lost or is too
 * creased to read: the till code is picked from a list and only the counter is
 * typed, because that is the part that changes.
 */

const emit = defineEmits(['back', 'found'])

const deviceCode = ref(session.device?.code ?? '')
const number = ref('')
const error = ref('')
const busy = ref(false)

async function lookup(fullNumber) {
    if (busy.value) return

    error.value = ''
    busy.value = true

    try {
        const response = await till.lookupReceipt(fullNumber)

        startReturn(response.data, response.meta)
        emit('found')
    } catch (e) {
        error.value = e instanceof ApiError ? e.message : 'حصل خطأ'
    } finally {
        busy.value = false
    }
}

function submit() {
    const digits = number.value.trim().replace(/\D/g, '')
    if (!digits) return

    // Receipts read A1-000123; the stored number is zero padded to six.
    lookup(`${deviceCode.value}-${digits.padStart(6, '0')}`)
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
      <h1>مرتجع</h1>
    </div>

    <p class="muted" style="margin: 0">صوّر الـ QR اللي على الإيصال، أو اكتب رقم الفاتورة.</p>

    <Scanner @code="lookup" />

    <p v-if="error" class="msg bad">{{ error }}</p>

    <form @submit.prevent="submit" style="display: flex; flex-direction: column; gap: 6px">
      <label class="muted" for="receipt-number">رقم الفاتورة</label>
      <div class="row">
        <div class="prefixed grow">
          <select
            v-model="deviceCode"
            class="input"
            style="width: auto; flex: none; background: var(--bg); border: 0; border-left: 1px solid var(--line); font-family: var(--mono); font-weight: 600"
            aria-label="الكاشير"
          >
            <option v-for="device in session.devices" :key="device.id" :value="device.code">
              {{ device.code }}-
            </option>
          </select>
          <input
            id="receipt-number"
            v-model="number"
            class="input code"
            placeholder="000001"
            inputmode="numeric"
            autocomplete="off"
          >
        </div>
        <button class="btn" type="submit" :disabled="busy">بحث</button>
      </div>
    </form>
  </div>
</template>
