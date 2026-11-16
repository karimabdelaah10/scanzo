<script setup>
import ReceiptDocument from '../../shared/ReceiptDocument.vue'
import { shopCurrency } from '../../shared/currency.js'

/**
 * The one screen that confirms a sale, a refund or an exchange. A sale and an
 * exchange also pass the invoice, which is drawn under the confirmation; a
 * refund passes none and stays a confirmation only.
 */
defineProps({
    invoice: { type: Object, default: null },
    title: { type: String, required: true },
    number: { type: String, required: true },
    amount: { type: String, required: true },
    amountLabel: { type: String, default: '' },
    note: { type: String, default: '' },
    nextLabel: { type: String, default: 'فاتورة جديدة' },
})

const emit = defineEmits(['next'])
</script>

<template>
  <div class="screen">
    <div class="center" style="display: flex; flex-direction: column; align-items: center; gap: 8px">
      <div
        class="chip"
        style="width: 84px; height: 84px; border-radius: 50%; justify-content: center"
        aria-hidden="true"
      >
        <svg width="44" height="44" viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round">
          <path d="M5 12l5 5 9-10" />
        </svg>
      </div>

      <h1 style="margin: 8px 0 0; font-size: 23px; font-weight: 700">{{ title }}</h1>
      <span class="mono muted">{{ number }}</span>

      <span v-if="amountLabel" class="muted">{{ amountLabel }}</span>
      <span class="total" style="font-size: 32px">{{ amount }} {{ shopCurrency() }}</span>

      <p v-if="note" class="muted" style="max-width: 34ch">{{ note }}</p>

      <button class="btn primary big" style="margin-top: 14px" @click="emit('next')">
        {{ nextLabel }}
      </button>
    </div>

    <ReceiptDocument v-if="invoice" :invoice="invoice" />
  </div>
</template>
