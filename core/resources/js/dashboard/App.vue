<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import Login from './components/Login.vue'
import Products from './views/Products.vue'
import ProductNew from './views/ProductNew.vue'
import Import from './views/Import.vue'
import Stock from './views/Stock.vue'
import StockReceive from './views/StockReceive.vue'
import StockCount from './views/StockCount.vue'
import StockTransfer from './views/StockTransfer.vue'
import StockTransfers from './views/StockTransfers.vue'
import Movements from './views/Movements.vue'
import Labels from './views/Labels.vue'
import Categories from './views/Categories.vue'
import Reports from './views/Reports.vue'
import Shifts from './views/Shifts.vue'
import Invoices from './views/Invoices.vue'
import InvoiceShow from './views/InvoiceShow.vue'
import Team from './views/Team.vue'
import Branches from './views/Branches.vue'
import Devices from './views/Devices.vue'
import Settings from './views/Settings.vue'
import { session, startSession, homeFor } from '../shared/session.js'
import Shell from './components/Shell.vue'
import { route } from './router.js'
import { view, pickInitialBranch, toast } from './lib.js'

const screens = {
    products: Products,
    'products.new': ProductNew,
    import: Import,
    stock: Stock,
    'stock.receive': StockReceive,
    'stock.count': StockCount,
    'stock.transfer': StockTransfer,
    'stock.transfers': StockTransfers,
    'stock.movements': Movements,
    labels: Labels,
    categories: Categories,
    reports: Reports,
    shifts: Shifts,
    invoices: Invoices,
    'invoices.show': InvoiceShow,
    team: Team,
    branches: Branches,
    devices: Devices,
    settings: Settings,
}

// The owner's screens. They are about the shop, not about one branch, so they
// work even when no branch is active (which is exactly when the owner needs
// the branches screen to switch one back on), and they do not reload when the
// branch picker moves.
const adminScreens = ['team', 'branches', 'devices', 'settings']

const fatal = ref('')

function reload() {
    window.location.reload()
}

onMounted(async () => {
    try {
        await startSession()
    } catch (e) {
        fatal.value = e?.message ?? 'مش قادر أوصل للسيرفر'
    }
})

// Whoever signs in (or the session coming back) gets a branch to look at.
watch(() => session.user?.id, (id) => { if (id) pickInitialBranch() }, { immediate: true })

// The seller never belongs here and nothing in the dashboard is theirs, so
// send them straight to the till. `replace` keeps Back from looping.
watch(() => session.user?.role, (role) => {
    if (role === 'seller') window.location.replace(homeFor(session.user))
}, { immediate: true })

const allowed = computed(() => session.user && session.user.role !== 'seller')
const isAdminScreen = computed(() => adminScreens.includes(route.value.name))
const isOwner = computed(() => session.user?.role === 'owner')
const screen = computed(() => screens[route.value.name])
// Screens that only exist to write. A suspended shop gets a notice instead of
// a form that would fail on the last tap; the server refuses it either way.
const writeOnlyScreens = ['products.new', 'import', 'stock.receive', 'stock.count', 'stock.transfer']
const blockedByReadOnly = computed(() => session.readOnly && writeOnlyScreens.includes(route.value.name))
// The invoices screens carry their own branch filter (all branches, for the
// owner), so the shell's picker does not drive them and must not reload them.
const ownsBranchFilter = computed(() => route.value.name.startsWith('invoices') || ['reports', 'shifts'].includes(route.value.name))
const viewKey = computed(() => route.value.name === 'products.new' || isAdminScreen.value || ownsBranchFilter.value
    ? `${route.value.name}:${route.value.params.id ?? ''}`
    : `${route.value.name}:${view.branchId}:${route.value.params.id ?? ''}`)
</script>

<template>
  <div class="dash" :class="{ framed: allowed }">
    <p v-if="session.loading" class="center muted">...</p>

    <div v-else-if="fatal" class="center">
      <p class="msg bad">{{ fatal }}</p>
      <button class="btn" style="margin-top: 12px" @click="reload">حاول تاني</button>
    </div>

    <Login v-else-if="!session.signedIn" />

    <!-- A seller is being redirected to the till; nothing to show meanwhile. -->
    <p v-else-if="!allowed" class="center muted">...</p>

    <Shell v-else>
      <p v-if="blockedByReadOnly" class="msg warn">
        الشاشة دي بتعدّل بيانات، والاشتراك متوقف. تقدر تتصفح باقي الشاشات للقراءة.
      </p>
      <p v-else-if="isAdminScreen && !isOwner" class="msg warn">الشاشة دي للمالك بس.</p>
      <component :is="screen" v-else-if="isAdminScreen" :key="viewKey" />
      <p v-else-if="!view.branchId" class="msg warn">مفيش فرع شغّال على الحساب ده.</p>
      <!-- Keyed by branch so every screen reloads when the branch changes; the
           new-product form is the exception, so a half-typed product survives. -->
      <component :is="screen" v-else :key="viewKey" />
    </Shell>

    <div v-if="toast.text" class="toast" role="status">{{ toast.text }}</div>
  </div>
</template>
