<script setup>
import { computed } from 'vue'
import { money, toMinor } from './money.js'
import { currencyLabel } from './currency.js'

/**
 * The receipt as a document, drawn from an invoice JSON.
 *
 * It reads only what is passed in — no request, no session — so the same
 * component can render a receipt that was saved offline.
 */
const props = defineProps({
    invoice: { type: Object, required: true },
})

const business = computed(() => props.invoice.business ?? {})
const branch = computed(() => props.invoice.branch ?? {})

// The API sends amounts as decimal strings ("12.50"); money() wants piastres.
const fmt = (amount) => money(toMinor(amount))

const issuedAt = computed(() => {
    if (!props.invoice.issued_at) return ''

    return new Date(props.invoice.issued_at).toLocaleString('ar-EG', {
        dateStyle: 'medium',
        timeStyle: 'short',
    })
})

const hasDiscount = computed(() => toMinor(props.invoice.discount_amount) > 0)

const returnNote = computed(() => {
    const days = business.value.return_window_days

    if (!days) return ''

    const n = Number(days).toLocaleString('ar-EG')

    return `المرتجع خلال ${n} ${days >= 3 && days <= 10 ? 'أيام' : 'يوم'} بالإيصال`
})
</script>

<template>
  <article class="receipt-doc" aria-label="إيصال">
    <!-- A cancelled invoice must not pass for a valid receipt, on screen or on paper. -->
    <p v-if="invoice.status === 'voided'" class="receipt-voided">فاتورة ملغية</p>

    <header class="receipt-head">
      <img v-if="business.logo" class="receipt-logo" :src="business.logo" alt="">
      <h2>{{ business.name }}</h2>
      <p v-if="branch.name">{{ branch.name }}</p>
      <p v-if="branch.address">{{ branch.address }}</p>
      <p v-if="branch.phone || business.phone" class="mono">{{ branch.phone || business.phone }}</p>
      <p v-if="business.tax_number">الرقم الضريبي: <span class="mono">{{ business.tax_number }}</span></p>
      <p v-if="business.receipt_header" class="receipt-note">{{ business.receipt_header }}</p>
    </header>

    <dl class="receipt-meta">
      <div><dt>رقم الفاتورة</dt><dd class="mono">{{ invoice.number }}</dd></div>
      <div v-if="issuedAt"><dt>التاريخ</dt><dd>{{ issuedAt }}</dd></div>
      <div v-if="invoice.seller?.name"><dt>البائع</dt><dd>{{ invoice.seller.name }}</dd></div>
      <div><dt>طريقة الدفع</dt><dd>{{ invoice.payment_method_label }}</dd></div>
      <div v-if="invoice.customer_name"><dt>العميل</dt><dd>{{ invoice.customer_name }}</dd></div>
    </dl>

    <ul class="receipt-lines">
      <li v-for="item in invoice.items" :key="item.id">
        <div class="row" style="align-items: flex-start">
          <div class="grow">
            <div style="font-weight: 600">{{ item.product_name }}</div>
            <div class="muted">
              <span v-if="item.variant_label">{{ item.variant_label }} · </span>
              <span class="mono">{{ item.sku }}</span>
            </div>
          </div>
          <div class="amount">{{ fmt(item.line_total) }}</div>
        </div>
        <div class="muted">{{ item.quantity }} × {{ fmt(item.unit_price) }}</div>
        <!-- Shown on the line itself, so the customer sees what they were
             given and the printed amount above is visibly the reduced one. -->
        <div v-if="toMinor(item.discount_amount) > 0" class="muted">
          خصم − {{ fmt(item.discount_amount) }}
        </div>
      </li>
    </ul>

    <div class="receipt-totals">
      <div class="row">
        <!-- The subtotal is the sum of the lines above, each already net of its own discount. -->
        <span class="grow muted">المجموع</span>
        <span class="amount">{{ fmt(invoice.subtotal) }}</span>
      </div>
      <div v-if="hasDiscount" class="row">
        <span class="grow muted">خصم الفاتورة</span>
        <span class="amount">− {{ fmt(invoice.discount_amount) }}</span>
      </div>
      <div class="row grand">
        <span class="grow">الإجمالي</span>
        <span class="total">{{ fmt(invoice.total) }} {{ currencyLabel(invoice.currency ?? 'EGP') }}</span>
      </div>
    </div>

    <footer class="receipt-foot">
      <p v-if="business.receipt_footer">{{ business.receipt_footer }}</p>
      <p v-if="returnNote" class="muted">{{ returnNote }}</p>
    </footer>
  </article>
</template>

<style scoped>
.receipt-doc {
  width: 100%;
  background: var(--surface);
  border-radius: var(--radius-lg);
  padding: 16px 14px;
  display: flex;
  flex-direction: column;
  gap: 14px;
  text-align: right;
}
.receipt-voided {
  margin: 0; text-align: center; font-weight: 700; font-size: 18px;
  border: 2px solid currentColor; border-radius: var(--radius-lg); padding: 6px;
  color: var(--bad, #b3261e);
}
.receipt-head { text-align: center; }
.receipt-head h2 { margin: 0 0 4px; font-size: 20px; font-weight: 700; }
.receipt-head p { margin: 2px 0; font-size: 14px; color: var(--muted); }
.receipt-head .receipt-note { color: var(--fg); margin-top: 8px; }
.receipt-logo { max-height: 56px; max-width: 60%; margin-bottom: 6px; }

.receipt-meta { margin: 0; display: flex; flex-direction: column; gap: 4px; font-size: 14px; }
.receipt-meta div { display: flex; gap: 8px; }
.receipt-meta dt { color: var(--muted); flex: none; }
.receipt-meta dd { margin: 0; margin-inline-start: auto; text-align: left; }

.receipt-lines {
  list-style: none; margin: 0; padding: 10px 0;
  border-top: 1px dashed var(--line); border-bottom: 1px dashed var(--line);
  display: flex; flex-direction: column; gap: 10px;
}
.amount { font-weight: 600; font-variant-numeric: tabular-nums; }

.receipt-totals { display: flex; flex-direction: column; gap: 6px; }
.receipt-totals .grand { align-items: baseline; font-weight: 700; padding-top: 6px; }

.receipt-foot { text-align: center; }
.receipt-foot p { margin: 2px 0; font-size: 14px; }
</style>
