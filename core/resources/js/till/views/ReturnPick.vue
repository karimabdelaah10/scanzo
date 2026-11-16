<script setup>
import { ref } from 'vue'
import { session } from '../../shared/session.js'
import { till, ApiError } from '../../shared/api.js'
import { money, toMinor } from '../cart.js'
import { returnDraft, returnPayload, setCondition, setPicked, clearReturn } from '../returns.js'

/**
 * Which pieces are coming back, in what state, and what happens to the money.
 *
 * "استبدال" does not refund here: it hands over to the selling screen to pick
 * the replacement, and both are saved together — so a customer can never walk
 * out having been refunded for a swap that then failed.
 */

const emit = defineEmits(['back', 'saved', 'exchange'])

const error = ref('')
const busy = ref(false)

async function refund() {
    error.value = ''
    busy.value = true

    try {
        const { data } = await till.saveReturn({
            device_id: session.deviceId,
            invoice_id: returnDraft.invoice.id,
            items: returnPayload(),
            refund_method: 'cash',
            reason: returnDraft.reason || null,
            override_window: returnDraft.overrideWindow,
        })

        clearReturn()
        emit('saved', data)
    } catch (e) {
        error.value = e instanceof ApiError ? e.message : 'حصل خطأ'
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
      <h1>مرتجع</h1>
      <span class="mono muted">{{ returnDraft.invoice?.number }}</span>
    </div>

    <p v-if="error" class="msg bad">{{ error }}</p>

    <!-- Past the window: say so, and say who can accept it anyway. -->
    <div v-if="!returnDraft.withinWindow" class="msg warn">
      الفاتورة دي بقالها أكتر من {{ returnDraft.windowDays }} يوم.
      <label v-if="returnDraft.mayOverrideWindow" class="row" style="margin-top: 8px; gap: 8px">
        <input v-model="returnDraft.overrideWindow" type="checkbox" style="width: 20px; height: 20px">
        <span>أقبلها على مسؤوليتي</span>
      </label>
      <div v-else style="margin-top: 4px">مدير الفرع هو اللي يقدر يقبلها.</div>
    </div>

    <div class="list">
      <div v-for="line in returnDraft.lines" :key="line.id" class="item">
        <div class="row" style="align-items: flex-start">
          <div class="grow">
            <div style="font-weight: 600">{{ line.product_name }}</div>
            <div class="muted">
              <span v-if="line.variant_label">{{ line.variant_label }} · </span>
              <span class="mono">{{ line.sku }}</span>
            </div>
          </div>
          <div class="muted" style="text-align: left">
            {{ line.unit_price }}
            <div v-if="toMinor(line.discount_amount) > 0" style="font-size: 12px">
              اتباعت بخصم {{ line.discount_amount }} على السطر
            </div>
            <div v-if="line.returned_quantity" style="font-size: 12px">
              رجع منها {{ line.returned_quantity }}
            </div>
          </div>
        </div>

        <!-- Nothing left to come back: shown, not hidden, so the seller can
             see the shop already took it. -->
        <p v-if="line.returnable_quantity === 0" class="muted" style="margin: 6px 0 2px">
          اترجعت كلها
        </p>

        <template v-else>
          <div class="row" style="margin-top: 6px">
            <span class="grow muted">من {{ line.returnable_quantity }}</span>
            <div class="stepper">
              <button
                class="step"
                :aria-label="`زود مرتجع ${line.product_name}`"
                :disabled="line.pick.quantity >= line.returnable_quantity"
                @click="setPicked(line, line.pick.quantity + 1)"
              >
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="2.2" stroke-linecap="round" aria-hidden="true">
                  <path d="M12 5v14M5 12h14" />
                </svg>
              </button>
              <span class="qty">{{ line.pick.quantity }}</span>
              <button
                class="step"
                :aria-label="`قلل مرتجع ${line.product_name}`"
                :disabled="line.pick.quantity <= 0"
                @click="setPicked(line, line.pick.quantity - 1)"
              >
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="2.2" stroke-linecap="round" aria-hidden="true">
                  <path d="M5 12h14" />
                </svg>
              </button>
            </div>
          </div>

          <div v-if="line.pick.quantity > 0" class="row" style="margin-top: 8px; gap: 6px">
            <button
              class="btn"
              style="min-height: 38px; font-size: 13px; flex: 1"
              :style="line.pick.condition === 'good' ? 'background: var(--accent); color: var(--accent-fg); border-color: transparent' : ''"
              @click="setCondition(line, 'good')"
            >
              سليمة
            </button>
            <button
              class="btn"
              style="min-height: 38px; font-size: 13px; flex: 1"
              :style="line.pick.condition === 'damaged' ? 'background: var(--bad); color: #fff; border-color: transparent' : ''"
              @click="setCondition(line, 'damaged')"
            >
              تالفة
            </button>
          </div>
          <p v-if="line.pick.quantity > 0 && line.pick.condition === 'damaged'" class="muted" style="margin: 4px 0 0">
            مش هترجع للرف، بس الفلوس هترجع
          </p>
        </template>
      </div>
    </div>

    <div class="card" style="flex: none; display: flex; flex-direction: column; gap: 10px">
      <div class="field">
        <label for="reason">السبب (اختياري)</label>
        <input id="reason" v-model="returnDraft.reason" class="input" placeholder="المقاس مش مناسب">
      </div>

      <div class="row" style="align-items: baseline">
        <span class="grow" style="font-weight: 700">قيمة المرتجع</span>
        <span class="total" style="font-size: 26px">{{ money(returnDraft.refundMinor) }}</span>
      </div>

      <button
        class="btn primary big full"
        :disabled="busy || !returnDraft.canSave"
        @click="refund"
      >
        {{ busy ? 'جاري الحفظ…' : 'استرداد كاش' }}
      </button>

      <button
        class="btn full"
        :disabled="busy || !returnDraft.canSave"
        @click="emit('exchange')"
      >
        استبدال بقطعة تانية
      </button>
    </div>
  </div>
</template>
