<script setup>
import { onMounted, ref } from 'vue'
import { href, route } from '../router.js'
import { dash } from '../api.js'
import { view, branchName } from '../lib.js'
import { session } from '../../shared/session.js'

// How many transfers wait for this user's branch to confirm. A convenience:
// failing to ask leaves the tab without a badge.
const awaiting = ref(0)

onMounted(async () => {
    try {
        awaiting.value = (await dash.transfers({ status: 'in_transit', box: 'incoming', branch_id: view.branchId })).meta.awaiting
    } catch (e) { /* no badge */ }
})
</script>

<template>
  <div class="page-head">
    <h2>المخزون <span class="muted">— {{ branchName(view.branchId) }}</span></h2>
  </div>
  <div class="subtabs">
    <a class="btn sm" :class="{ on: route.name === 'stock' }" :href="href('/stock')">الأرصدة</a>
    <!-- Both write to the stock ledger, so a suspended shop does not get them. -->
    <template v-if="!session.readOnly">
      <a class="btn sm" :class="{ on: route.name === 'stock.receive' }" :href="href('/stock/receive')">استلام بضاعة</a>
      <a class="btn sm" :class="{ on: route.name === 'stock.count' }" :href="href('/stock/count')">جرد</a>
      <a class="btn sm" :class="{ on: route.name === 'stock.transfer' }" :href="href('/stock/transfer')">تحويل بين الفروع</a>
    </template>
    <!-- Reading and confirming: outside the read-only block so a suspended shop still sees where its stock is. -->
    <a class="btn sm" :class="{ on: route.name === 'stock.transfers' }" :href="href('/stock/transfers')">
      التحويلات<span v-if="awaiting" class="badge warn" style="margin-inline-start: 6px">{{ awaiting }}</span>
    </a>
  </div>
</template>
