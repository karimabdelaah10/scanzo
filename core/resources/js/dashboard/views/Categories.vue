<script setup>
import { computed, onMounted, ref } from 'vue'
import ConfirmButton from '../components/ConfirmButton.vue'
import Field from '../components/Field.vue'
import { dash } from '../api.js'
import { session } from '../../shared/session.js'
import { fail, fieldMessage, say } from '../lib.js'

/*
 * List, add, rename, delete. The server refuses to delete a category that has
 * products or sub-categories (the foreign keys would otherwise null them out
 * silently), and the reason is shown on the row it came from.
 */

const categories = ref([])
const loading = ref(true)
const error = ref('')

const form = ref(null)        // { id, name, parentId }
const nameError = ref('')
const formError = ref('')
const busy = ref(false)
const rowError = ref({})      // category id -> why its delete was refused

const parents = computed(() => categories.value)   // the list is top level only
const readOnly = computed(() => session.readOnly)

async function load() {
    loading.value = true
    error.value = ''

    try {
        categories.value = (await dash.categories()).data
    } catch (e) {
        error.value = fail(e)
    } finally {
        loading.value = false
    }
}

onMounted(load)

function reset() {
    nameError.value = ''
    formError.value = ''
}

function openNew(parentId = '') {
    reset()
    form.value = { id: null, name: '', parentId }
}

function openEdit(c) {
    reset()
    form.value = { id: c.id, name: c.name, parentId: c.parent_id ?? '' }
}

async function save() {
    const f = form.value
    reset()

    if (!f.name.trim()) {
        nameError.value = 'اكتب اسم التصنيف'
        return
    }

    busy.value = true

    try {
        // A rename sends the name alone: it must not touch where the category sits.
        if (f.id) await dash.updateCategory(f.id, { name: f.name.trim() })
        else await dash.createCategory(f.name.trim(), f.parentId)

        form.value = null
        say(f.id ? 'اتحفظ التعديل' : 'اتضاف التصنيف')
        await load()
    } catch (e) {
        formError.value = fail(e)
        nameError.value = fieldMessage(e, 'name')
    } finally {
        busy.value = false
    }
}

async function remove(c) {
    rowError.value = { ...rowError.value, [c.id]: '' }

    try {
        await dash.deleteCategory(c.id)
        say('اتمسح التصنيف')
        await load()
    } catch (e) {
        rowError.value = { ...rowError.value, [c.id]: fail(e) }
    }
}
</script>

<template>
  <div class="stack">
    <div class="page-head">
      <h2>التصنيفات</h2>
      <button v-if="!readOnly" class="btn primary" @click="openNew()">+ تصنيف جديد</button>
    </div>

    <form v-if="form" class="card stack form-card" @submit.prevent="save">
      <strong>{{ form.id ? 'تعديل اسم التصنيف' : 'تصنيف جديد' }}</strong>
      <p v-if="formError" class="msg bad">{{ formError }}</p>

      <Field id="c-name" label="الاسم" :error="nameError">
        <input id="c-name" v-model="form.name" class="input" autocomplete="off" autofocus>
      </Field>

      <Field v-if="!form.id" id="c-parent" label="جوّه تصنيف (اختياري)">
        <select id="c-parent" v-model="form.parentId" class="input">
          <option value="">— تصنيف رئيسي —</option>
          <option v-for="p in parents" :key="p.id" :value="p.id">{{ p.name }}</option>
        </select>
      </Field>

      <div class="row">
        <button class="btn primary" type="submit" :disabled="busy">{{ busy ? '...' : 'حفظ' }}</button>
        <button class="btn quiet" type="button" @click="form = null">إلغاء</button>
      </div>
    </form>

    <p v-if="error" class="msg bad">{{ error }}</p>
    <p v-if="loading" class="center muted">...</p>
    <p v-else-if="!categories.length && !error" class="center muted">مفيش تصنيفات لسه.</p>

    <div v-if="categories.length" class="card stack">
      <div v-for="c in categories" :key="c.id" class="stack cat">
        <div class="row">
          <strong class="grow">{{ c.name }}</strong>
          <template v-if="!readOnly">
            <button class="btn sm" @click="openNew(c.id)">+ فرعي</button>
            <button class="btn sm" @click="openEdit(c)">تعديل</button>
            <ConfirmButton @confirm="remove(c)" />
          </template>
        </div>
        <p v-if="rowError[c.id]" class="msg bad">{{ rowError[c.id] }}</p>

        <template v-for="k in c.children" :key="k.id">
          <div class="row child">
            <span class="grow">{{ k.name }}</span>
            <template v-if="!readOnly">
              <button class="btn sm" @click="openEdit(k)">تعديل</button>
              <ConfirmButton @confirm="remove(k)" />
            </template>
          </div>
          <p v-if="rowError[k.id]" class="msg bad child">{{ rowError[k.id] }}</p>
        </template>
      </div>
    </div>

    <p class="muted">
      التصنيف اللي فيه منتجات أو تصنيفات فرعية مينفعش يتمسح.
    </p>
  </div>
</template>

<style scoped>
.cat { gap: 6px; padding-bottom: 8px; border-bottom: 1px solid var(--line, #e5e5e5); }
.cat:last-child { border-bottom: 0; padding-bottom: 0; }
.child { padding-inline-start: 20px; }
</style>
