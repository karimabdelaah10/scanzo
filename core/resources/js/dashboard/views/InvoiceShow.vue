<script setup>
import { onMounted, ref } from 'vue'
import ReceiptDocument from '../../shared/ReceiptDocument.vue'
import VoidPanel from '../../shared/VoidPanel.vue'
import { dash } from '../api.js'
import { href, route } from '../router.js'
import { dateTime, fail } from '../lib.js'

/**
 * One past invoice, drawn by the same ReceiptDocument the till prints. Its
 * route carries the id, so a refresh or a shared link lands here again.
 */
const invoice = ref(null)
const error = ref('')

onMounted(async () => {
    try {
        invoice.value = (await dash.invoice(route.value.params.id)).data
    } catch (e) {
        error.value = fail(e)
    }
})
</script>

<template>
  <div class="stack">
    <div class="page-head">
      <h2>الفاتورة <span v-if="invoice" class="mono muted">{{ invoice.number }}</span></h2>
      <a class="btn quiet" :href="href('/invoices')">رجوع للفواتير</a>
    </div>

    <p v-if="error" class="msg bad">{{ error }}</p>
    <p v-else-if="!invoice" class="center muted">...</p>

    <div v-else class="receipt-wrap stack">
      <p v-if="invoice.status === 'voided'" class="msg warn">
        الفاتورة دي ملغية<span v-if="invoice.voided_by_name"> بواسطة {{ invoice.voided_by_name }}</span><span v-if="invoice.voided_at"> · {{ dateTime(invoice.voided_at) }}</span>.
        <span v-if="invoice.void_reason"><br>السبب: {{ invoice.void_reason }}</span>
      </p>

      <VoidPanel :invoice="invoice" @voided="(updated) => (invoice = updated)" />

      <ReceiptDocument :invoice="invoice" />
    </div>
  </div>
</template>

<style scoped>
.receipt-wrap { max-width: 440px; width: 100%; margin-inline: auto; }
</style>
