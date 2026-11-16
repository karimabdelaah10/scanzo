<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import StockTabs from '../components/StockTabs.vue'
import { dash } from '../api.js'
import { view, branchName, dateTime, toInt, fail, say } from '../lib.js'
import { session } from '../../shared/session.js'

/**
 * Transfers between branches, from the point of view of the branch the shell
 * is looking at.
 *
 * Incoming comes first because it is where somebody has to act: what was sent
 * to this branch and has not been confirmed. Quantities start at what was
 * sent; changing one down is how a short delivery is reported, and the
 * difference is kept on the transfer, not dropped.
 */
const TABS = [
    ['incoming', 'وارد'],
    ['outgoing', 'اللي بعتّه'],
    ['shortage', 'الفروقات'],
]

const STATUS = {
    in_transit: ['في الطريق', 'warn'],
    received: ['اتسلّم', 'ok'],
    cancelled: ['اتلغى', ''],
}

const tab = ref('incoming')
const rows = ref([])
const awaiting = ref(0)
const loading = ref(true)
const error = ref('')
// Quantities being typed, per transfer: { [transferId]: { [variantId]: '3' } }
const typed = reactive({})
const busyId = ref('')

const isOwner = computed(() => session.user?.role === 'owner')
const canWrite = computed(() => !session.readOnly && session.user?.role !== 'seller')

// The receiving branch's manager, or the owner. The sender is not either.
const canReceive = (t) => canWrite.value && (isOwner.value || session.user?.branch_id === t.to_branch_id)
const canCancel = (t) => canWrite.value && (isOwner.value || session.user?.branch_id === t.from_branch_id)

async function load() {
    loading.value = true
    error.value = ''

    const params = { branch_id: view.branchId }
    if (tab.value === 'shortage') params.shortage = 1
    else params.box = tab.value

    try {
        const res = await dash.transfers(params)
        rows.value = res.data
        awaiting.value = res.meta.awaiting
        lateCount.value = res.meta.late

        for (const t of rows.value) {
            if (t.status === 'in_transit' && !typed[t.id]) {
                typed[t.id] = Object.fromEntries(t.items.map((l) => [l.variant_id, String(l.quantity_sent)]))
            }
        }
    } catch (e) {
        error.value = fail(e)
    } finally {
        loading.value = false
    }
}

onMounted(load)

function pick(next) {
    tab.value = next
    load()
}

// Settling a shortage: pieces that were short turned up. They go into the
// branch they really are in, and the reason is required.
const settling = reactive({}) // { [transferId]: { branch, reason, qty: { [variantId]: '' } } }
const lateCount = ref(0)

// The owner picks either branch; a manager only their own, and only if it is one of the two.
const settleBranches = (t) => [t.from_branch_id, t.to_branch_id].filter((id) => isOwner.value || id === session.user?.branch_id)
const canSettle = (t) => canWrite.value && t.has_shortage && settleBranches(t).length > 0

function openSettle(t) {
    settling[t.id] = {
        branch: settleBranches(t).includes(t.to_branch_id) ? t.to_branch_id : settleBranches(t)[0],
        reason: '',
        qty: Object.fromEntries(t.items.filter((l) => l.quantity_open_short > 0).map((l) => [l.variant_id, ''])),
    }
}

const settleLines = (t) => t.items.filter((l) => l.quantity_open_short > 0)
const settleQty = (t, l) => toInt(settling[t.id]?.qty[l.variant_id]) ?? 0
const settleValid = (t) => {
    const s = settling[t.id]
    const lines = settleLines(t)

    return s && s.reason.trim() !== ''
        && lines.every((l) => settleQty(t, l) >= 0 && settleQty(t, l) <= l.quantity_open_short)
        && lines.some((l) => settleQty(t, l) > 0)
        && busyId.value === ''
}

async function settle(t) {
    if (!settleValid(t)) return
    const s = settling[t.id]
    busyId.value = t.id
    error.value = ''

    try {
        await dash.settleTransfer(t.id, {
            branch_id: s.branch,
            reason: s.reason.trim(),
            items: settleLines(t)
                .filter((l) => settleQty(t, l) > 0)
                .map((l) => ({ variant_id: l.variant_id, quantity: settleQty(t, l) })),
        })
        say('اتسجّلت التسوية، والقطع دخلت ' + branchName(s.branch))
        delete settling[t.id]
        await load()
    } catch (e) {
        error.value = fail(e)
    } finally {
        busyId.value = ''
    }
}

const received = (t, line) => toInt(typed[t.id]?.[line.variant_id])
const validLine = (t, line) => received(t, line) !== null && received(t, line) >= 0 && received(t, line) <= line.quantity_sent
const shortBy = (t) => t.items.reduce((s, l) => s + (validLine(t, l) ? l.quantity_sent - received(t, l) : 0), 0)
const canConfirm = (t) => t.items.every((l) => validLine(t, l)) && busyId.value === ''

async function confirm(t) {
    if (!canConfirm(t)) return
    busyId.value = t.id
    error.value = ''

    try {
        await dash.receiveTransfer(t.id, t.items.map((l) => ({ variant_id: l.variant_id, quantity: received(t, l) })))
        say(shortBy(t) > 0 ? 'اتأكد الاستلام، والنقص اتسجّل' : 'اتأكد الاستلام')
        delete typed[t.id]
        await load()
    } catch (e) {
        error.value = fail(e)
    } finally {
        busyId.value = ''
    }
}

async function cancel(t) {
    if (busyId.value !== '' || !window.confirm('تلغي التحويل وترجّع القطع لفرع المصدر؟')) return
    busyId.value = t.id
    error.value = ''

    try {
        await dash.cancelTransfer(t.id)
        say('اتلغى التحويل ورجعت القطع للمصدر')
        await load()
    } catch (e) {
        error.value = fail(e)
    } finally {
        busyId.value = ''
    }
}
</script>

<template>
  <div class="stack">
    <StockTabs />

    <p v-if="awaiting" class="msg warn">
      فيه {{ awaiting }} تحويل في الطريق مستني تأكيد الاستلام.
    </p>

    <p v-if="lateCount && isOwner" class="msg bad">
      فيه {{ lateCount }} تحويل متأخر: في الطريق من أكتر من المدة المحددة في الإعدادات ومحدش أكّد استلامه.
    </p>

    <div class="subtabs">
      <button
        v-for="[key, label] in TABS"
        :key="key"
        class="btn sm"
        :class="{ on: tab === key }"
        type="button"
        @click="pick(key)"
      >
        {{ label }}
      </button>
    </div>

    <p v-if="error" class="msg bad">{{ error }}</p>
    <p v-if="loading" class="center muted">...</p>

    <p v-else-if="!rows.length" class="card center muted">
      {{ tab === 'shortage' ? 'مفيش فروقات في أي تحويل.' : 'مفيش تحويلات هنا.' }}
    </p>

    <div v-for="t in rows" :key="t.id" class="card stack">
      <div class="row">
        <div class="grow">
          <strong>{{ branchName(t.from_branch_id) }} ← {{ branchName(t.to_branch_id) }}</strong>
          <div class="muted">
            اتبعت {{ dateTime(t.sent_at) }} · {{ t.sent_by }}
            <template v-if="t.received_at"> · اتسلّم {{ dateTime(t.received_at) }} · {{ t.received_by }}</template>
            <template v-if="t.cancelled_at"> · اتلغى {{ dateTime(t.cancelled_at) }} · {{ t.cancelled_by }}</template>
          </div>
          <div v-if="t.note" class="muted">{{ t.note }}</div>
        </div>
        <span class="badge" :class="STATUS[t.status][1]">{{ STATUS[t.status][0] }}</span>
        <span v-if="t.is_late" class="badge bad">متأخر · أكتر من {{ t.late_after_days }} يوم</span>
        <span v-if="t.has_shortage" class="badge bad">نقص {{ t.pieces_short }}</span>
        <span v-else-if="t.pieces_settled" class="badge ok">النقص اتسوّى</span>
      </div>

      <div class="tbl-wrap">
        <table class="tbl stacks">
          <thead>
            <tr>
              <th>القطعة</th>
              <th>المبعوت</th>
              <th v-if="t.status !== 'cancelled'">المستلم</th>
              <th v-if="t.has_shortage || t.pieces_settled">النقص</th>
              <th v-if="t.pieces_settled">اتسوّى</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="l in t.items" :key="l.variant_id">
              <td class="id">{{ l.product_name }} · {{ l.variant_label }} <span class="mono muted">{{ l.sku }}</span></td>
              <td data-label="المبعوت" class="num">{{ l.quantity_sent }}</td>
              <td v-if="t.status === 'received'" data-label="المستلم" class="num">{{ l.quantity_received }}</td>
              <td v-else-if="t.status === 'in_transit'" data-label="المستلم">
                <input
                  v-if="canReceive(t)"
                  v-model="typed[t.id][l.variant_id]"
                  class="input cell"
                  :class="{ invalid: !validLine(t, l) }"
                  inputmode="numeric"
                  :aria-label="`المستلم من ${l.product_name}`"
                >
                <span v-else class="muted">—</span>
              </td>
              <td v-if="t.has_shortage || t.pieces_settled" data-label="النقص" class="num">
                <span v-if="l.quantity_open_short" class="diff minus">{{ l.quantity_open_short }}</span>
                <span v-else-if="l.quantity_short" class="muted">{{ l.quantity_short }}</span>
              </td>
              <td v-if="t.pieces_settled" data-label="اتسوّى" class="num">{{ l.quantity_settled || '' }}</td>
            </tr>
          </tbody>
        </table>
      </div>

      <ul v-if="t.settlements?.length" class="muted">
        <li v-for="(s, i) in t.settlements" :key="i">
          تسوية {{ s.quantity }} → {{ branchName(s.branch_id) }} · {{ dateTime(s.settled_at) }} · {{ s.settled_by }} — {{ s.reason }}
        </li>
      </ul>

      <template v-if="canSettle(t)">
        <button v-if="!settling[t.id]" class="btn sm" type="button" @click="openSettle(t)">سوّي النقص (القطع اتلقت)</button>
        <div v-else class="stack">
          <p class="muted">النقص مش بيتمسح: بيتنقل لفرع. اختار الفرع اللي القطع دخلته فعلاً واكتب السبب.</p>
          <label class="row">الفرع
            <select v-model="settling[t.id].branch" class="input">
              <option v-for="id in settleBranches(t)" :key="id" :value="id">
                {{ branchName(id) }} {{ id === t.to_branch_id ? '(وصلت متأخر)' : '(ماتحركتش أصلاً)' }}
              </option>
            </select>
          </label>
          <label v-for="l in settleLines(t)" :key="l.variant_id" class="row">
            {{ l.product_name }} · {{ l.variant_label }} (ناقص {{ l.quantity_open_short }})
            <input
              v-model="settling[t.id].qty[l.variant_id]"
              class="input cell"
              inputmode="numeric"
              placeholder="0"
              :aria-label="`المتسوّى من ${l.product_name}`"
            >
          </label>
          <input v-model="settling[t.id].reason" class="input" maxlength="500" placeholder="السبب (مطلوب)" aria-label="سبب التسوية">
          <div class="row">
            <button class="btn primary" type="button" :disabled="!settleValid(t)" @click="settle(t)">
              {{ busyId === t.id ? '...' : 'سجّل التسوية' }}
            </button>
            <button class="btn quiet" type="button" @click="delete settling[t.id]">إلغاء</button>
          </div>
        </div>
      </template>

      <template v-if="t.status === 'in_transit'">
        <p v-if="canReceive(t) && shortBy(t) > 0" class="msg warn">
          هتستلم أقل من المبعوت بـ {{ shortBy(t) }} قطعة. النقص هيتسجّل على التحويل ده ويظهر للمالك.
        </p>
        <p v-if="!canReceive(t)" class="muted">
          في الطريق لحد ما {{ branchName(t.to_branch_id) }} يأكد الاستلام.
        </p>
        <div class="row">
          <button v-if="canReceive(t)" class="btn primary" :disabled="!canConfirm(t)" @click="confirm(t)">
            {{ busyId === t.id ? '...' : 'أكّد الاستلام' }}
          </button>
          <button v-if="canCancel(t)" class="btn quiet danger" :disabled="busyId !== ''" @click="cancel(t)">
            إلغاء التحويل
          </button>
        </div>
      </template>
    </div>
  </div>
</template>
