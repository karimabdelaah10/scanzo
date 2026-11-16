<script setup>
import { onMounted, reactive, ref } from 'vue'
import Field from '../components/Field.vue'
import { dash } from '../api.js'
import ConfirmButton from '../components/ConfirmButton.vue'
import { session } from '../../shared/session.js'
import { fail, fieldMessage, say, refreshAfterAdmin } from '../lib.js'

/*
 * Delete is only for a branch set up by mistake. BranchController::destroy
 * refuses one with invoices or stock movements, because stock exists only as
 * that ledger and it cascades with the branch. The screen then offers to
 * switch the branch off instead.
 */
const REFUSED_FOR_HISTORY = ['branch_has_history', 'branch_has_stock_history']

const branches = ref([])
const loading = ref(true)
const error = ref('')
const rowError = reactive({})   // branchId -> { text, offerOff }

const form = ref(null)
const fieldErrors = ref({})
const formError = ref('')
const busy = ref(false)

async function load() {
    loading.value = true
    error.value = ''

    try {
        branches.value = (await dash.branches()).data
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
    form.value = { id: null, name: '', code: '', address: '', phone: '' }
}

function openEdit(b) {
    fieldErrors.value = {}
    formError.value = ''
    form.value = { id: b.id, name: b.name, code: b.code, address: b.address ?? '', phone: b.phone ?? '' }
}

async function save() {
    const f = form.value
    const errs = {}

    if (!f.name.trim()) errs.name = 'اكتب اسم الفرع'
    if (!f.code.trim()) errs.code = 'اكتب كود الفرع'
    else if (!/^[A-Za-z0-9_-]{1,8}$/.test(f.code.trim())) errs.code = 'حروف إنجليزي وأرقام بس، لحد 8'

    fieldErrors.value = errs
    formError.value = ''
    if (Object.keys(errs).length) return

    const body = {
        name: f.name.trim(),
        code: f.code.trim().toUpperCase(),
        address: f.address.trim() || null,
        phone: f.phone.trim() || null,
    }

    busy.value = true

    try {
        if (f.id) await dash.updateBranch(f.id, body)
        else await dash.createBranch(body)

        form.value = null
        say(f.id ? 'اتحفظ التعديل' : 'اتضاف الفرع')
        await load()
        await refreshAfterAdmin()
    } catch (e) {
        formError.value = fail(e)
        fieldErrors.value = Object.fromEntries(
            ['name', 'code', 'address', 'phone'].map((k) => [k, fieldMessage(e, k)]).filter(([, v]) => v),
        )
    } finally {
        busy.value = false
    }
}

async function toggle(b) {
    delete rowError[b.id]

    try {
        await dash.updateBranch(b.id, { is_active: !b.is_active })
        say(b.is_active ? 'اتوقف الفرع' : 'اتفعّل الفرع')
        await load()
        await refreshAfterAdmin()
    } catch (e) {
        rowError[b.id] = { text: fail(e) }
    }
}

async function remove(b) {
    delete rowError[b.id]

    try {
        await dash.deleteBranch(b.id)
        say('اتمسح الفرع')
        await load()
        await refreshAfterAdmin()
    } catch (e) {
        rowError[b.id] = { text: fail(e), offerOff: REFUSED_FOR_HISTORY.includes(e.code) && b.is_active }
    }
}
</script>

<template>
  <div class="stack">
    <div class="page-head">
      <h2>الفروع</h2>
      <button v-if="!session.readOnly" class="btn primary" @click="openNew">+ فرع جديد</button>
    </div>

    <form v-if="form" class="card stack form-card" @submit.prevent="save">
      <strong>{{ form.id ? 'تعديل فرع' : 'فرع جديد' }}</strong>
      <p v-if="formError" class="msg bad">{{ formError }}</p>

      <div class="two">
        <Field id="b-name" label="اسم الفرع" :error="fieldErrors.name">
          <input id="b-name" v-model="form.name" class="input" autocomplete="off">
        </Field>
        <Field id="b-code" label="الكود" hint="اختصار قصير للفرع، إنجليزي، مش بيتكرر" :error="fieldErrors.code">
          <input id="b-code" v-model="form.code" class="input code" maxlength="8" autocomplete="off">
        </Field>
      </div>

      <div class="two">
        <Field id="b-address" label="العنوان (اختياري)" :error="fieldErrors.address">
          <input id="b-address" v-model="form.address" class="input" autocomplete="off">
        </Field>
        <Field id="b-phone" label="التليفون (اختياري)" :error="fieldErrors.phone">
          <input id="b-phone" v-model="form.phone" class="input code" inputmode="tel" autocomplete="off">
        </Field>
      </div>

      <div class="row">
        <button class="btn primary" type="submit" :disabled="busy">{{ busy ? '...' : 'حفظ' }}</button>
        <button class="btn quiet" type="button" @click="form = null">إلغاء</button>
      </div>
    </form>

    <p v-if="error" class="msg bad">{{ error }}</p>
    <p v-if="loading" class="center muted">...</p>
    <p v-else-if="!branches.length && !error" class="center muted">مفيش فروع.</p>

    <div v-if="branches.length" class="card tbl-wrap">
      <table class="tbl stacks">
        <thead>
          <tr><th>الفرع</th><th>الكود</th><th>مستخدمين</th><th>أجهزة</th><th>الحالة</th><th></th></tr>
        </thead>
        <tbody>
          <template v-for="b in branches" :key="b.id">
            <tr :class="{ off: !b.is_active }">
              <td class="id">
                <div>
                  <strong>{{ b.name }}</strong>
                  <div v-if="b.address || b.phone" class="muted">{{ [b.address, b.phone].filter(Boolean).join(' · ') }}</div>
                </div>
              </td>
              <td data-label="الكود"><span class="mono">{{ b.code }}</span></td>
              <td data-label="مستخدمين" class="num">{{ b.users_count }}</td>
              <td data-label="أجهزة" class="num">{{ b.devices_count }}</td>
              <td data-label="الحالة">
                <span class="badge" :class="b.is_active ? 'ok' : 'warn'">{{ b.is_active ? 'شغّال' : 'موقوف' }}</span>
              </td>
              <td class="actions">
                <div v-if="!session.readOnly" class="row wrap">
                  <button class="btn sm" @click="openEdit(b)">تعديل</button>
                  <button class="btn sm" @click="toggle(b)">{{ b.is_active ? 'أوقف' : 'فعّل' }}</button>
                  <ConfirmButton @confirm="remove(b)" />
                </div>
              </td>
            </tr>
            <tr v-if="rowError[b.id]" class="note">
              <td class="span" colspan="6">
                <p class="msg bad">
                  {{ rowError[b.id].text }}
                  <button v-if="rowError[b.id].offerOff" class="btn sm" style="margin-inline-start: 8px" @click="toggle(b)">أوقف الفرع</button>
                </p>
              </td>
            </tr>
          </template>
        </tbody>
      </table>
    </div>

    <p class="muted">المسح للفرع اللي اتعمل بالغلط بس. أي فرع اتباع منه أو استلم بضاعة بيتوقف ومبيتمسحش، وتاريخه بيفضل محفوظ.</p>
  </div>
</template>
