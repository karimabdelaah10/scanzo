<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import Field from '../components/Field.vue'
import ConfirmButton from '../components/ConfirmButton.vue'
import { session } from '../../shared/session.js'
import { dash } from '../api.js'
import { fail, fieldMessage, say, dateTime, refreshAfterAdmin } from '../lib.js'

const TYPES = [
    { value: 'mobile', label: 'موبايل' },
    { value: 'desktop', label: 'كمبيوتر' },
]

const devices = ref([])
const branches = ref([])
const branchFilter = ref('')
const loading = ref(true)
const error = ref('')
const rowError = reactive({})    // deviceId -> { text, offerOff }

const form = ref(null)
const fieldErrors = ref({})
const formError = ref('')
const busy = ref(false)

const branchName = (id) => branches.value.find((b) => b.id === id)?.name ?? '—'
const typeLabel = (v) => TYPES.find((t) => t.value === v)?.label ?? v

// One block per branch, so "which tills does this branch have" reads at a glance.
const groups = computed(() => branches.value
    .filter((b) => !branchFilter.value || b.id === branchFilter.value)
    .map((b) => ({ branch: b, devices: devices.value.filter((d) => d.branch_id === b.id) }))
    .filter((g) => g.devices.length))

async function load() {
    loading.value = true
    error.value = ''

    try {
        const [d, b] = await Promise.all([dash.devices(), dash.branches()])
        devices.value = d.data
        branches.value = b.data
    } catch (e) {
        error.value = fail(e)
    } finally {
        loading.value = false
    }
}

onMounted(load)

function openNew() {
    fieldErrors.value = {}
    formError.value = ''
    form.value = {
        id: null,
        name: '',
        type: 'mobile',
        branch_id: branchFilter.value || (branches.value.find((b) => b.is_active)?.id ?? ''),
    }
}

function openEdit(d) {
    fieldErrors.value = {}
    formError.value = ''
    form.value = { id: d.id, name: d.name, type: d.type, branch_id: d.branch_id, code: d.code, prefix: d.invoice_prefix }
}

async function save() {
    const f = form.value
    const errs = {}

    if (!f.name.trim()) errs.name = 'اكتب اسم الجهاز'
    if (!f.branch_id) errs.branch_id = 'اختار الفرع'

    fieldErrors.value = errs
    formError.value = ''
    if (Object.keys(errs).length) return

    busy.value = true

    try {
        // The code is never sent: the server generates it on create and it
        // cannot be changed afterwards.
        if (f.id) await dash.updateDevice(f.id, { name: f.name.trim(), type: f.type })
        else await dash.createDevice({ name: f.name.trim(), type: f.type, branch_id: f.branch_id })

        form.value = null
        say(f.id ? 'اتحفظ التعديل' : 'اتضاف الجهاز')
        await load()
        await refreshAfterAdmin()
    } catch (e) {
        formError.value = fail(e)
        fieldErrors.value = Object.fromEntries(
            ['name', 'type', 'branch_id'].map((k) => [k, fieldMessage(e, k)]).filter(([, v]) => v),
        )
    } finally {
        busy.value = false
    }
}

async function toggle(d) {
    delete rowError[d.id]

    try {
        await dash.updateDevice(d.id, { is_active: !d.is_active })
        say(d.is_active ? 'اتوقف الجهاز' : 'اتفعّل الجهاز')
        await load()
        await refreshAfterAdmin()
    } catch (e) {
        rowError[d.id] = { text: fail(e) }
    }
}

async function remove(d) {
    delete rowError[d.id]

    try {
        await dash.deleteDevice(d.id)
        say('اتمسح الجهاز')
        await load()
        await refreshAfterAdmin()
    } catch (e) {
        rowError[d.id] = { text: fail(e), offerOff: e.code === 'device_has_history' && d.is_active }
    }
}
</script>

<template>
  <div class="stack">
    <div class="page-head">
      <h2>الأجهزة (الكواشير)</h2>
      <button v-if="!session.readOnly" class="btn primary" @click="openNew">+ جهاز جديد</button>
    </div>

    <p class="msg ok">
      كل جهاز ليه كود (A1 وبعده A2…) بيتحط أول كل رقم فاتورة بيكتبها، زي <span class="mono">A1-</span>.
      فالجهاز مش مجرد اسم: الكود بيتولّد لوحده ومبيتغيّرش، وأي فاتورة تفضل تعرّف إنهي كاشير كتبها.
    </p>

    <form v-if="form" class="card stack form-card" @submit.prevent="save">
      <strong>{{ form.id ? 'تعديل جهاز' : 'جهاز جديد' }}</strong>
      <p v-if="formError" class="msg bad">{{ formError }}</p>

      <div class="two">
        <Field id="d-name" label="الاسم" :error="fieldErrors.name">
          <input id="d-name" v-model="form.name" class="input" autocomplete="off" placeholder="مثلاً: كاشير الدخول">
        </Field>
        <Field id="d-type" label="النوع" :error="fieldErrors.type">
          <select id="d-type" v-model="form.type" class="input">
            <option v-for="t in TYPES" :key="t.value" :value="t.value">{{ t.label }}</option>
          </select>
        </Field>
      </div>

      <div class="two">
        <Field v-if="!form.id" id="d-branch" label="الفرع" :error="fieldErrors.branch_id">
          <select id="d-branch" v-model="form.branch_id" class="input">
            <option value="" disabled>اختار الفرع</option>
            <option v-for="b in branches.filter((x) => x.is_active)" :key="b.id" :value="b.id">{{ b.name }}</option>
          </select>
        </Field>
        <div v-else class="field">
          <label>الفرع</label>
          <span>{{ branchName(form.branch_id) }}</span>
        </div>

        <div class="field">
          <label>الكود</label>
          <span v-if="form.id" class="mono">{{ form.code }} <span class="muted">· الفواتير بتبدأ بـ {{ form.prefix }}</span></span>
          <span v-else class="muted">هيتولّد لوحده أول ما تحفظ.</span>
        </div>
      </div>

      <div class="row">
        <button class="btn primary" type="submit" :disabled="busy">{{ busy ? '...' : 'حفظ' }}</button>
        <button class="btn quiet" type="button" @click="form = null">إلغاء</button>
      </div>
    </form>

    <div v-if="branches.length > 1" class="toolbar">
      <select v-model="branchFilter" class="input" aria-label="الفرع">
        <option value="">كل الفروع</option>
        <option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}</option>
      </select>
    </div>

    <p v-if="error" class="msg bad">{{ error }}</p>
    <p v-if="loading" class="center muted">...</p>
    <p v-else-if="!groups.length && !error" class="center muted">مفيش أجهزة.</p>

    <section v-for="g in groups" :key="g.branch.id" class="card stack">
      <strong>{{ g.branch.name }} <span v-if="!g.branch.is_active" class="badge warn">موقوف</span></strong>

      <div class="tbl-wrap">
        <table class="tbl stacks">
          <thead>
            <tr><th>الاسم</th><th>الكود</th><th>النوع</th><th>آخر اتصال</th><th>الحالة</th><th></th></tr>
          </thead>
          <tbody>
            <template v-for="d in g.devices" :key="d.id">
              <tr :class="{ off: !d.is_active }">
                <td class="id">{{ d.name }}</td>
                <td data-label="الكود"><span class="mono">{{ d.code }}</span> <span class="muted mono">{{ d.invoice_prefix }}</span></td>
                <td data-label="النوع">{{ typeLabel(d.type) }}</td>
                <td data-label="آخر اتصال">{{ dateTime(d.last_sync_at) || '—' }}</td>
                <td data-label="الحالة">
                  <span class="badge" :class="d.is_active ? 'ok' : 'warn'">{{ d.is_active ? 'شغّال' : 'موقوف' }}</span>
                </td>
                <td class="actions">
                  <div v-if="!session.readOnly" class="row wrap">
                    <button class="btn sm" @click="openEdit(d)">تعديل</button>
                    <button class="btn sm" @click="toggle(d)">{{ d.is_active ? 'أوقف' : 'فعّل' }}</button>
                    <ConfirmButton @confirm="remove(d)" />
                  </div>
                </td>
              </tr>
              <tr v-if="rowError[d.id]" class="note">
                <td class="span" colspan="6">
                  <p class="msg bad">
                    {{ rowError[d.id].text }}
                    <button v-if="rowError[d.id].offerOff" class="btn sm" style="margin-inline-start: 8px" @click="toggle(d)">أوقف الجهاز</button>
                  </p>
                </td>
              </tr>
            </template>
          </tbody>
        </table>
      </div>
    </section>
  </div>
</template>
