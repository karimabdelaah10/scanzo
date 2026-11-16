<script setup>
import { computed, ref } from 'vue'
import { ApiError } from '../../shared/api.js'
import { dash } from '../api.js'
import { view, branchName, fail, say } from '../lib.js'

/*
 * Upload a catalogue file. All or nothing: the server either saves the whole
 * file or saves nothing and lists every row that is wrong, so the owner fixes
 * the file once and uploads again.
 *
 * The branch shown is the one the dashboard is looking at. It is where opening
 * stock goes for rows that name no branch of their own.
 */
const file = ref(null)
const input = ref(null)
const busy = ref(false)
const error = ref('')
const rejected = ref(null)  // { count, rows, errors } when the file had problems
const done = ref(null)

const FIELDS = {
    name: 'اسم المنتج', category: 'التصنيف', size: 'المقاس', color: 'اللون', price: 'السعر',
    cost: 'التكلفة', barcode: 'الباركود', opening_stock: 'الرصيد الافتتاحي', branch: 'الفرع',
}

const hidden = computed(() => rejected.value ? rejected.value.count - rejected.value.errors.length : 0)

function pick(e) {
    file.value = e.target.files?.[0] ?? null
    error.value = ''
    rejected.value = null
}

async function upload() {
    if (!file.value || busy.value) return

    busy.value = true
    error.value = ''
    rejected.value = null
    done.value = null

    try {
        done.value = (await dash.importCatalogue(file.value, view.branchId)).data
        say('اتسجّل الكتالوج')
        file.value = null
        if (input.value) input.value.value = ''
    } catch (e) {
        if (e instanceof ApiError && e.code === 'import_rejected') {
            rejected.value = {
                count: e.context.error_count,
                rows: e.context.rows,
                errors: e.context.errors,
            }
        } else if (e instanceof ApiError && e.code === 'import_library_missing') {
            // The one failure that is not the shop's to fix: show the command.
            error.value = `${fail(e)}\n${e.context.command}`
        } else {
            error.value = fail(e)
        }
    } finally {
        busy.value = false
    }
}
</script>

<template>
  <div class="stack">
    <h2>استيراد منتجات من ملف</h2>

    <div class="card stack">
      <p>
        كل سطر في الملف = مقاس ولون واحد. الأسطر اللي ليها نفس اسم المنتج بتتجمع في منتج واحد.
        الأكواد بتتعمل أوتوماتيك، والرصيد الافتتاحي بيتسجّل كاستلام.
      </p>
      <p class="muted">
        لو في خطأ في سطر واحد، الملف كله مش بيتسجّل وبتشوف كل الأخطاء مرة واحدة.
        منتج اسمه موجود قبل كده بيترفض، فرفع نفس الملف مرتين مش بيعمل تكرار.
        الحد الأقصى 1000 سطر في الملف.
      </p>
      <div class="row wrap">
        <a class="btn" href="/api/catalogue/import/template" download>تحميل القالب (Excel)</a>
        <a class="btn quiet" href="/api/catalogue/import/template?format=csv" download>القالب كـ CSV</a>
      </div>
    </div>

    <div class="card stack">
      <div class="field">
        <label for="catalogue-file">الملف (xlsx أو csv)</label>
        <input
          id="catalogue-file"
          ref="input"
          class="input"
          type="file"
          accept=".xlsx,.csv,.txt,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
          @change="pick"
        >
      </div>
      <p class="muted">
        أي سطر فيه رصيد افتتاحي من غير فرع هيتسجّل في: <strong>{{ branchName(view.branchId) }}</strong>
      </p>

      <p v-if="error" class="msg bad" style="white-space: pre-line">
        {{ error.split('\n')[0] }}
        <span v-if="error.includes('\n')" class="mono" style="display: block; margin-top: 6px">{{ error.split('\n')[1] }}</span>
      </p>

      <button class="btn primary big" :disabled="!file || busy" @click="upload">
        {{ busy ? '...' : 'رفع واستيراد' }}
      </button>
    </div>

    <!-- Nothing was saved. Every problem, by row. -->
    <div v-if="rejected" class="card stack">
      <p class="msg bad">
        في الملف {{ rejected.count }} خطأ من {{ rejected.rows }} سطر، ومفيش حاجة اتسجّلت.
        صلّح الأخطاء وارفع الملف تاني.
      </p>

      <table class="tbl stacks">
        <thead>
          <tr><th>العمود</th><th>سطر</th><th>المشكلة</th></tr>
        </thead>
        <tbody>
          <tr v-for="(e, i) in rejected.errors" :key="i">
            <td class="id">{{ FIELDS[e.field] ?? e.field }}</td>
            <td data-label="سطر" class="mono">{{ e.row }}</td>
            <td data-label="المشكلة">{{ e.message }}</td>
          </tr>
        </tbody>
      </table>

      <p v-if="hidden > 0" class="muted">
        وفيه {{ hidden }} خطأ تانيين مش معروضين. صلّح اللي فوق وارفع تاني وهيظهروا.
      </p>
    </div>

    <!-- Saved. What went in, so a typo in a category is seen here. -->
    <div v-if="done" class="card stack">
      <p class="msg ok">
        اتسجّل {{ done.products }} منتج ({{ done.variants }} مقاس/لون)
        <template v-if="done.stock_movements">
          ورصيد افتتاحي {{ done.stock_pieces }} قطعة على {{ done.stock_movements }} صنف.
        </template>
      </p>

      <div v-if="done.categories_created.length" class="stack">
        <strong>تصنيفات جديدة اتعملت</strong>
        <div class="row wrap">
          <span v-for="c in done.categories_created" :key="c" class="badge warn">{{ c }}</span>
        </div>
        <p class="muted">
          لو واحد منهم مكتوب غلط، غيّر اسمه أو انقل منتجاته من شاشة التصنيفات.
        </p>
      </div>

      <p v-for="s in done.similar_categories" :key="s.name" class="msg warn">
        «{{ s.name }}» اتعمل كتصنيف جديد، وفيه تصنيف شبهه «{{ s.like }}». لو هما نفس الحاجة، صلّح من شاشة التصنيفات.
      </p>

      <div v-if="done.categories_existing.length" class="stack">
        <strong>تصنيفات كانت موجودة واتستعملت</strong>
        <div class="row wrap">
          <span v-for="c in done.categories_existing" :key="c" class="badge">{{ c }}</span>
        </div>
      </div>

      <p v-if="done.ignored_columns.length" class="muted">
        أعمدة اتجاهلت لأنها مش معروفة: {{ done.ignored_columns.join('، ') }}
      </p>

      <a class="btn" href="#/products">المنتجات</a>
    </div>
  </div>
</template>
