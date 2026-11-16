<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { session, signOut } from '../../shared/session.js'
import { route, href } from '../router.js'
import { view, chooseViewBranch, branchName } from '../lib.js'
import StatusBanner from './StatusBanner.vue'

/**
 * The frame around every dashboard screen: a fixed side column on a wide
 * screen, and on a narrow one a slim top bar that opens the same column as a
 * drawer. One markup, two layouts: the switch is CSS only (see dashboard.css),
 * so nothing here forks by device.
 *
 * A section is listed only once its screen exists, and only for the roles that
 * may use it (CLAUDE.md, "The front end"). Add invoices / reports here when
 * their screens ship, not before. The owner's own group ("الإدارة") is hidden
 * from everyone else, and so is its heading.
 */
const sections = [
    { key: 'products', label: 'المنتجات', path: '/products', roles: ['owner', 'branch_manager'] },
    { key: 'import', label: 'استيراد', path: '/import', roles: ['owner', 'branch_manager'] },
    { key: 'stock', label: 'المخزون', path: '/stock', roles: ['owner', 'branch_manager'] },
    { key: 'categories', label: 'التصنيفات', path: '/categories', roles: ['owner', 'branch_manager'] },
    { key: 'labels', label: 'الاستيكرات', path: '/labels', roles: ['owner', 'branch_manager'] },
    { key: 'invoices', label: 'الفواتير', path: '/invoices', roles: ['owner', 'branch_manager'] },
    { key: 'shifts', label: 'الورديات', path: '/shifts', roles: ['owner', 'branch_manager'] },
    { key: 'reports', label: 'التقارير', path: '/reports', roles: ['owner', 'branch_manager'] },
    { key: 'team', label: 'الفريق', path: '/team', roles: ['owner'], group: 'admin' },
    { key: 'branches', label: 'الفروع', path: '/branches', roles: ['owner'], group: 'admin' },
    { key: 'devices', label: 'الأجهزة', path: '/devices', roles: ['owner'], group: 'admin' },
    { key: 'settings', label: 'الإعدادات', path: '/settings', roles: ['owner'], group: 'admin' },
]

const role = computed(() => session.user?.role)
const allowedLinks = computed(() => sections.filter((s) => s.roles.includes(role.value)))
const links = computed(() => allowedLinks.value.filter((s) => s.group !== 'admin'))
const adminLinks = computed(() => allowedLinks.value.filter((s) => s.group === 'admin'))
const logo = computed(() => session.business?.brand?.logo ?? null)
const canSell = computed(() => ['owner', 'branch_manager'].includes(role.value))

const current = computed(() => route.value.name.split('.')[0])
// Not on the invoices screens: they filter by branch themselves, and a picker
// that does nothing there would only mislead.
const showBranchPicker = computed(() => session.user?.sees_all_branches
    && session.branches.length > 1
    && !['invoices', 'reports', 'shifts'].includes(current.value))

const open = ref(false)
const close = () => { open.value = false }
const side = ref(null)
const menuBtn = ref(null)

// Picking a section on a phone should land on the screen, not leave the drawer
// covering it.
watch(() => route.value.name, close)

// The drawer exists only under 900px (dashboard.css). Above it the same element
// is the fixed column, so none of the below may trap focus or lock the page.
const asDrawer = () => window.matchMedia('(max-width: 899.98px)').matches
const FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'

// An open drawer is a dialog: focus moves into it, the page behind it stops
// scrolling, and focus goes back to the button that opened it on the way out.
watch(open, async (isOpen, wasOpen) => {
    document.documentElement.classList.toggle('drawer-open', isOpen && asDrawer())
    if (isOpen && asDrawer()) {
        await nextTick()
        side.value?.querySelector(FOCUSABLE)?.focus()
    } else if (wasOpen && asDrawer()) {
        menuBtn.value?.focus()
    }
})

function onKey(e) {
    if (e.key === 'Escape') return close()

    // Tab wraps inside the open drawer and never reaches the page behind it.
    if (e.key !== 'Tab' || !open.value || !asDrawer() || !side.value) return
    const items = [...side.value.querySelectorAll(FOCUSABLE)].filter((el) => el.offsetParent !== null)
    if (!items.length) return
    const first = items[0]
    const last = items[items.length - 1]
    if (!side.value.contains(document.activeElement)) { e.preventDefault(); first.focus() }
    else if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus() }
    else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus() }
}

onMounted(() => window.addEventListener('keydown', onKey))
onBeforeUnmount(() => {
    window.removeEventListener('keydown', onKey)
    document.documentElement.classList.remove('drawer-open')
})
</script>

<template>
  <div class="shell">
    <!-- Narrow screens only: the drawer's handle. -->
    <header class="shell-top">
      <button
        ref="menuBtn"
        class="icon-btn ghost"
        aria-label="القايمة"
        aria-controls="shell-side"
        :aria-expanded="open"
        @click="open = !open"
      >
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="2.2" stroke-linecap="round" aria-hidden="true">
          <path d="M4 7h16M4 12h16M4 17h16" />
        </svg>
      </button>
      <h1 class="brand">
        <img v-if="logo" class="brand-logo" :src="logo" alt="">
        <span>{{ session.business?.name }}</span>
      </h1>
      <a v-if="canSell" class="btn primary sm" href="/till">بيع</a>
    </header>

    <div v-if="open" class="shell-scrim" @click="close"></div>

    <aside
      id="shell-side"
      ref="side"
      class="shell-side"
      :class="{ open }"
      :role="open && asDrawer() ? 'dialog' : undefined"
      :aria-modal="open && asDrawer() ? 'true' : undefined"
      aria-label="القايمة"
    >
      <h1 class="brand">
        <img v-if="logo" class="brand-logo" :src="logo" alt="">
        <span>{{ session.business?.name }}</span>
      </h1>

      <!-- A plain link: the till is a different app, so the browser should treat
           it as a navigation (back button, open in new tab). -->
      <a v-if="canSell" class="btn primary full" href="/till">بيع</a>

      <nav aria-label="الأقسام">
        <a
          v-for="s in links"
          :key="s.key"
          :href="href(s.path)"
          :class="{ on: current === s.key }"
          :aria-current="current === s.key ? 'page' : undefined"
          @click="close"
        >{{ s.label }}</a>

        <template v-if="adminLinks.length">
          <p class="nav-title">الإدارة</p>
          <a
            v-for="s in adminLinks"
            :key="s.key"
            :href="href(s.path)"
            :class="{ on: current === s.key }"
            :aria-current="current === s.key ? 'page' : undefined"
            @click="close"
          >{{ s.label }}</a>
        </template>
      </nav>

      <div class="shell-foot">
        <select
          v-if="showBranchPicker"
          class="input"
          :value="view.branchId"
          aria-label="الفرع"
          @change="chooseViewBranch($event.target.value)"
        >
          <option v-for="b in session.branches" :key="b.id" :value="b.id">{{ b.name }}</option>
        </select>
        <span v-else-if="view.branchId" class="chip">{{ branchName(view.branchId) }}</span>

        <button class="btn quiet sm" @click="signOut">خروج ({{ session.user.name }})</button>
      </div>
    </aside>

    <main class="shell-main">
      <StatusBanner />
      <slot />
    </main>
  </div>
</template>
