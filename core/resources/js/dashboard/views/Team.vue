<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import Field from '../components/Field.vue'
import ConfirmButton from '../components/ConfirmButton.vue'
import { dash } from '../api.js'
import { session } from '../../shared/session.js'
import { fail, fieldMessage, say } from '../lib.js'

const ROLES = [
    { value: 'owner', label: 'مالك' },
    { value: 'branch_manager', label: 'مدير فرع' },
    { value: 'seller', label: 'بائع' },
]

const users = ref([])
const branches = ref([])
const loading = ref(true)
const error = ref('')
const rowError = reactive({})    // userId -> { text, offerOff }

const form = ref(null)           // null = closed; { id|null, ...fields }
const fieldErrors = ref({})
const formError = ref('')
const busy = ref(false)

const branchName = (id) => branches.value.find((b) => b.id === id)?.name ?? '—'
const roleLabel = (value) => ROLES.find((r) => r.value === value)?.label ?? value
const isMe = (u) => u.id === session.user.id
const isOwnerRole = computed(() => form.value?.role === 'owner')

async function load() {
    loading.value = true
    error.value = ''

    try {
        const [u, b] = await Promise.all([dash.users(), dash.branches()])
        users.value = u.data
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
    form.value = { id: null, name: '', email: '', phone: '', password: '', role: 'seller', branch_id: branches.value.find((b) => b.is_active)?.id ?? '' }
}

function openEdit(u) {
    fieldErrors.value = {}
    formError.value = ''
    form.value = { id: u.id, name: u.name, email: u.email, phone: u.phone ?? '', password: '', role: u.role, branch_id: u.branch_id ?? '' }
}

function validate(f) {
    const errs = {}

    if (!f.name.trim()) errs.name = 'اكتب الاسم'
    if (!f.email.trim()) errs.email = 'اكتب الإيميل'
    if (!f.id && f.password.length < 8) errs.password = 'الباسورد 8 حروف على الأقل'
    if (f.id && f.password && f.password.length < 8) errs.password = 'الباسورد 8 حروف على الأقل'
    // Everyone but an owner belongs to a branch.
    if (f.role !== 'owner' && !f.branch_id) errs.branch_id = 'اختار الفرع اللي بيشتغل فيه'

    return errs
}

async function save() {
    const f = form.value
    fieldErrors.value = validate(f)
    formError.value = ''
    if (Object.keys(fieldErrors.value).length) return

    const body = {
        name: f.name.trim(),
        email: f.email.trim(),
        phone: f.phone.trim() || null,
        role: f.role,
        branch_id: f.role === 'owner' ? null : f.branch_id,
    }
    if (f.password) body.password = f.password

    busy.value = true

    try {
        if (f.id) await dash.updateUser(f.id, body)
        else await dash.createUser(body)

        form.value = null
        say(f.id ? 'اتحفظ التعديل' : 'اتضاف المستخدم')
        await load()
    } catch (e) {
        formError.value = fail(e)
        fieldErrors.value = Object.fromEntries(
            ['name', 'email', 'phone', 'password', 'role', 'branch_id']
                .map((k) => [k, fieldMessage(e, k)])
                .filter(([, v]) => v),
        )
    } finally {
        busy.value = false
    }
}

async function toggle(u) {
    delete rowError[u.id]

    try {
        await dash.updateUser(u.id, { is_active: !u.is_active })
        say(u.is_active ? 'اتوقف الحساب' : 'اتفعّل الحساب')
        await load()
    } catch (e) {
        rowError[u.id] = { text: fail(e) }
    }
}

async function remove(u) {
    delete rowError[u.id]

    try {
        await dash.deleteUser(u.id)
        say('اتمسح المستخدم')
        await load()
    } catch (e) {
        // A user who sold something cannot go: offer the switch-off instead.
        rowError[u.id] = { text: fail(e), offerOff: e.code === 'user_has_history' && u.is_active }
    }
}
</script>

<template>
  <div class="stack">
    <div class="page-head">
      <h2>الفريق</h2>
      <button v-if="!session.readOnly" class="btn primary" @click="openNew">+ مستخدم جديد</button>
    </div>

    <form v-if="form" class="card stack form-card" @submit.prevent="save">
      <strong>{{ form.id ? 'تعديل مستخدم' : 'مستخدم جديد' }}</strong>
      <p v-if="formError" class="msg bad">{{ formError }}</p>

      <div class="two">
        <Field id="u-name" label="الاسم" :error="fieldErrors.name">
          <input id="u-name" v-model="form.name" class="input" autocomplete="off">
        </Field>
        <Field id="u-email" label="الإيميل (بيدخل بيه)" :error="fieldErrors.email">
          <input id="u-email" v-model="form.email" class="input code" type="email" autocomplete="off">
        </Field>
      </div>

      <div class="two">
        <Field id="u-phone" label="التليفون (اختياري)" :error="fieldErrors.phone">
          <input id="u-phone" v-model="form.phone" class="input code" inputmode="tel" autocomplete="off">
        </Field>
        <Field
          id="u-pass"
          :label="form.id ? 'باسورد جديد' : 'الباسورد'"
          :hint="form.id ? 'سيبه فاضي لو مش عايز تغيّره' : '8 حروف على الأقل'"
          :error="fieldErrors.password"
        >
          <input id="u-pass" v-model="form.password" class="input code" type="password" autocomplete="new-password">
        </Field>
      </div>

      <div class="two">
        <Field id="u-role" label="الدور" :error="fieldErrors.role">
          <select id="u-role" v-model="form.role" class="input" :disabled="form.id === session.user.id">
            <option v-for="r in ROLES" :key="r.value" :value="r.value">{{ r.label }}</option>
          </select>
        </Field>
        <Field
          v-if="!isOwnerRole"
          id="u-branch"
          label="الفرع"
          :error="fieldErrors.branch_id"
        >
          <select id="u-branch" v-model="form.branch_id" class="input">
            <option value="" disabled>اختار الفرع</option>
            <option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}{{ b.is_active ? '' : ' (موقوف)' }}</option>
          </select>
        </Field>
        <p v-else class="muted" style="align-self: end">المالك بيشتغل على كل الفروع.</p>
      </div>

      <div class="row">
        <button class="btn primary" type="submit" :disabled="busy">{{ busy ? '...' : 'حفظ' }}</button>
        <button class="btn quiet" type="button" @click="form = null">إلغاء</button>
      </div>
    </form>

    <p v-if="error" class="msg bad">{{ error }}</p>
    <p v-if="loading" class="center muted">...</p>
    <p v-else-if="!users.length && !error" class="center muted">مفيش مستخدمين.</p>

    <div v-if="users.length" class="card tbl-wrap">
      <table class="tbl stacks">
        <thead>
          <tr><th>الاسم</th><th>الدور</th><th>الفرع</th><th>الحالة</th><th></th></tr>
        </thead>
        <tbody>
          <template v-for="u in users" :key="u.id">
            <tr :class="{ off: !u.is_active }">
              <td class="id">
                <div>
                  <strong>{{ u.name }}</strong> <span v-if="isMe(u)" class="badge">إنت</span>
                  <div class="muted mono">{{ u.email }}</div>
                </div>
              </td>
              <td data-label="الدور">{{ roleLabel(u.role) }}</td>
              <td data-label="الفرع">{{ u.role === 'owner' ? 'كل الفروع' : branchName(u.branch_id) }}</td>
              <td data-label="الحالة">
                <span class="badge" :class="u.is_active ? 'ok' : 'warn'">{{ u.is_active ? 'شغّال' : 'موقوف' }}</span>
              </td>
              <td class="actions">
                <div v-if="!session.readOnly" class="row wrap">
                  <button class="btn sm" @click="openEdit(u)">تعديل</button>
                  <!-- Your own account cannot be switched off or deleted from here. -->
                  <template v-if="!isMe(u)">
                    <button class="btn sm" @click="toggle(u)">{{ u.is_active ? 'أوقف' : 'فعّل' }}</button>
                    <ConfirmButton @confirm="remove(u)" />
                  </template>
                </div>
              </td>
            </tr>
            <tr v-if="rowError[u.id]" class="note">
              <td class="span" colspan="5">
                <p class="msg bad">
                  {{ rowError[u.id].text }}
                  <button v-if="rowError[u.id].offerOff" class="btn sm" style="margin-inline-start: 8px" @click="toggle(u)">أوقف الحساب</button>
                </p>
              </td>
            </tr>
          </template>
        </tbody>
      </table>
    </div>
  </div>
</template>
