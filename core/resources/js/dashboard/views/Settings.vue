<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import Field from '../components/Field.vue'
import { dash } from '../api.js'
import { session } from '../../shared/session.js'
import { fail, fieldMessage, say, toInt, TEMPLATES, refreshAfterAdmin } from '../lib.js'

const loading = ref(true)
const error = ref('')
const busy = ref(false)
const fieldErrors = ref({})

const f = reactive({
    window_days: '14',
    void_hours: '24',
    transfer_late_days: '2',
    sellers_allowed: false,
    without_invoice: false,
    discount_sellers_allowed: false,
    discount_max_percent: '20',
    quick_scan: false,
    label_size: TEMPLATES[0].value,
    label_show_price: true,
    currency: 'EGP',
    receipt_header: '',
    receipt_footer: '',
    // '' means "none picked": the platform's own palette.
    brand_primary: '',
    brand_secondary: '',
})

// What the two colours become once the contrast rule has run (server side:
// one rule, one place). Drives the preview and the "we adjusted it" warning.
const preview = ref(null)
const logoUrl = ref(null)
const logoBusy = ref(false)
const logoError = ref('')
// <input type="color"> cannot hold "nothing", so show white until one is picked.
const swatch = (v) => v || '#ffffff'

let previewTimer = null
function refreshPreview() {
    clearTimeout(previewTimer)
    previewTimer = setTimeout(async () => {
        try {
            const { data } = await dash.previewBrand({ primary: f.brand_primary, secondary: f.brand_secondary })
            preview.value = data
        } catch (e) { /* the preview is a nicety; saving still works */ }
    }, 150)
}
watch(() => [f.brand_primary, f.brand_secondary], refreshPreview)

const pvStyle = computed(() => {
    const p = preview.value
    return {
        '--pv-accent': p?.accent ?? undefined,
        '--pv-accent-fg': p?.accent_fg ?? undefined,
        '--pv-side': p?.side ?? undefined,
        '--pv-side-fg': p?.side_fg ?? undefined,
    }
})

async function pickLogo(event) {
    const file = event.target.files?.[0]
    event.target.value = ''
    if (!file) return
    logoBusy.value = true
    logoError.value = ''
    try {
        const { data } = await dash.uploadLogo(file)
        logoUrl.value = data.brand.logo
        say('اتحدّث اللوجو')
        await refreshAfterAdmin()
    } catch (e) {
        logoError.value = fieldMessage(e, 'logo') || fail(e)
    } finally {
        logoBusy.value = false
    }
}

async function dropLogo() {
    logoBusy.value = true
    logoError.value = ''
    try {
        await dash.removeLogo()
        logoUrl.value = null
        await refreshAfterAdmin()
    } catch (e) {
        logoError.value = fail(e)
    } finally {
        logoBusy.value = false
    }
}

onMounted(async () => {
    try {
        const { data } = await dash.settings()

        f.window_days = String(data.returns.window_days)
        f.void_hours = String(data.voids.window_hours)
        f.transfer_late_days = String(data.transfers.late_after_days)
        f.sellers_allowed = data.returns.sellers_allowed
        f.without_invoice = data.returns.without_invoice
        f.discount_sellers_allowed = data.discounts.sellers_allowed
        f.discount_max_percent = String(data.discounts.max_percent)
        f.quick_scan = data.selling.quick_scan
        // An older value ("40x30mm") is not one of the sheet templates: show the default.
        f.label_size = TEMPLATES.some((t) => t.value === data.labels.size) ? data.labels.size : TEMPLATES[0].value
        f.label_show_price = data.labels.show_price
        f.currency = data.currency
        f.receipt_header = data.receipt?.header ?? ''
        f.receipt_footer = data.receipt?.footer ?? ''
        f.brand_primary = data.brand?.primary ?? ''
        f.brand_secondary = data.brand?.secondary ?? ''
        logoUrl.value = data.brand?.logo ?? null
        preview.value = data.brand ?? null
    } catch (e) {
        error.value = fail(e)
    } finally {
        loading.value = false
    }
})

async function save() {
    const errs = {}
    const days = toInt(f.window_days)

    if (days === null || days < 0 || days > 365) errs.window_days = 'رقم من 0 لـ 365'
    const hours = toInt(f.void_hours)
    if (hours === null || hours < 1 || hours > 720) errs.void_hours = 'رقم من 1 لـ 720'
    const lateDays = toInt(f.transfer_late_days)
    if (lateDays === null || lateDays < 1 || lateDays > 90) errs.transfer_late_days = 'رقم من 1 لـ 90'
    const pct = toInt(f.discount_max_percent)
    if (pct === null || pct < 0 || pct > 100) errs.discount_max_percent = 'رقم من 0 لـ 100'
    if (!/^[A-Za-z]{3}$/.test(f.currency.trim())) errs.currency = '3 حروف، زي EGP'

    fieldErrors.value = errs
    error.value = ''
    if (Object.keys(errs).length) return

    busy.value = true

    try {
        await dash.updateSettings({
            returns: { window_days: days, sellers_allowed: f.sellers_allowed, without_invoice: f.without_invoice },
            discounts: { sellers_allowed: f.discount_sellers_allowed, max_percent: pct },
            voids: { window_hours: hours },
            transfers: { late_after_days: lateDays },
            selling: { quick_scan: f.quick_scan },
            labels: { size: f.label_size, show_price: f.label_show_price },
            currency: f.currency.trim().toUpperCase(),
            receipt: {
                header: f.receipt_header.trim() || null,
                footer: f.receipt_footer.trim() || null,
            },
            brand: { primary: f.brand_primary || null, secondary: f.brand_secondary || null },
        })

        say('اتحفظت الإعدادات')
        await refreshAfterAdmin()
    } catch (e) {
        error.value = fail(e)
        fieldErrors.value = {
            window_days: fieldMessage(e, 'returns.window_days'),
            void_hours: fieldMessage(e, 'voids.window_hours'),
            transfer_late_days: fieldMessage(e, 'transfers.late_after_days'),
            discount_max_percent: fieldMessage(e, 'discounts.max_percent'),
            currency: fieldMessage(e, 'currency'),
            brand_primary: fieldMessage(e, 'brand.primary'),
            brand_secondary: fieldMessage(e, 'brand.secondary'),
            header: fieldMessage(e, 'receipt.header'),
            footer: fieldMessage(e, 'receipt.footer'),
        }
    } finally {
        busy.value = false
    }
}
</script>

<template>
  <div class="stack">
    <div class="page-head"><h2>الإعدادات</h2></div>

    <p v-if="error" class="msg bad">{{ error }}</p>
    <p v-if="loading" class="center muted">...</p>

    <form v-else class="stack" @submit.prevent="save">
      <!-- A suspended shop sees its settings and cannot change them. A
           disabled fieldset covers every control inside it, so a field added
           later is covered too. -->
      <fieldset class="bare stack" :disabled="session.readOnly">
      <section class="card stack">
        <strong>المرتجع</strong>
        <Field id="s-days" label="مدة المرتجع (بالأيام)" hint="بعد المدة دي مدير الفرع بس هو اللي يقبل المرتجع" :error="fieldErrors.window_days">
          <input id="s-days" v-model="f.window_days" class="input code" inputmode="numeric" style="max-width: 140px">
        </Field>
        <label class="switch-row"><input v-model="f.sellers_allowed" type="checkbox">البائع يقدر يعمل مرتجع (مش مدير الفرع بس)</label>
        <label class="switch-row"><input v-model="f.without_invoice" type="checkbox">قبول مرتجع من غير الفاتورة</label>
      </section>

      <section class="card stack">
        <strong>الخصم على السطر</strong>
        <label class="switch-row"><input v-model="f.discount_sellers_allowed" type="checkbox">البائع يقدر يدّي خصم — على سطر أو على الفاتورة كلها (مش مدير الفرع بس)</label>
        <Field id="s-disc" label="أقصى نسبة خصم للبائع (%)" hint="بتتطبق على خصم السطر (نسبة من سعر السطر) وعلى مجموع خصم الأصناف وخصم الفاتورة معاً (نسبة من إجمالي الفاتورة قبل أي خصم). مدير الفرع والمالك يخصموا بلا حد." :error="fieldErrors.discount_max_percent">
          <input id="s-disc" v-model="f.discount_max_percent" class="input code" inputmode="numeric" style="max-width: 140px">
        </Field>
      </section>

      <section class="card stack">
        <strong>إلغاء الفواتير</strong>
        <Field id="s-void" label="مدة الإلغاء (بالساعات)" hint="مدير الفرع أو المالك يقدر يلغي الفاتورة خلال المدة دي من وقت إصدارها. الفاتورة اللي عليها مرتجع مبتتلغيش." :error="fieldErrors.void_hours">
          <input id="s-void" v-model="f.void_hours" class="input code" inputmode="numeric" style="max-width: 140px">
        </Field>
      </section>

      <section class="card stack">
        <strong>تحويلات المخزون</strong>
        <Field id="s-late" label="تحويل متأخر بعد (أيام)" hint="تحويل في الطريق ومحدش أكّد استلامه بعد المدة دي بيتعلّم «متأخر» في شاشة التحويلات." :error="fieldErrors.transfer_late_days">
          <input id="s-late" v-model="f.transfer_late_days" class="input code" inputmode="numeric" style="max-width: 140px">
        </Field>
      </section>

      <section class="card stack">
        <strong>البيع</strong>
        <label class="switch-row"><input v-model="f.quick_scan" type="checkbox">المسح السريع: كل مسحة تنزل السلة على طول من غير ما تفتح القطعة الأول</label>
        <Field id="s-currency" label="العملة" hint="بتظهر في الكاشير والسلة والإيصال" :error="fieldErrors.currency">
          <input id="s-currency" v-model="f.currency" class="input code" maxlength="3" style="max-width: 140px">
        </Field>
      </section>

      <section class="card stack">
        <strong>الاستيكرات</strong>
        <Field id="s-size" label="المقاس" hint="شاشة الاستيكرات بتبدأ بالمقاس ده">
          <select id="s-size" v-model="f.label_size" class="input" style="max-width: 280px">
            <option v-for="t in TEMPLATES" :key="t.value" :value="t.value">{{ t.label }}</option>
          </select>
        </Field>
        <label class="switch-row"><input v-model="f.label_show_price" type="checkbox">اعرض السعر على الاستيكر</label>
      </section>

      <section class="card stack">
        <strong>الإيصال</strong>
        <Field id="s-header" label="سطر فوق الإيصال" :error="fieldErrors.header">
          <input id="s-header" v-model="f.receipt_header" class="input" maxlength="300">
        </Field>
        <Field id="s-footer" label="سطر تحت الإيصال" :error="fieldErrors.footer">
          <input id="s-footer" v-model="f.receipt_footer" class="input" maxlength="300">
        </Field>
      </section>

      <section class="card stack">
        <strong>شكل المحل</strong>
        <p class="muted" style="margin: 0">
          اللون الأساسي للأزرار والعنصر المختار واللينكات. اللون التاني للقايمة الجانبية والشريط اللي فوق.
          لون الكتابة فوقهم بيتحسب لوحده عشان يتقري.
        </p>

        <div class="two">
          <Field id="s-primary" label="اللون الأساسي" :error="fieldErrors.brand_primary">
            <div class="colour-row">
              <input id="s-primary" type="color" :value="swatch(f.brand_primary)" @input="f.brand_primary = $event.target.value">
              <span class="mono muted">{{ f.brand_primary || 'ألوان المنصة' }}</span>
              <button v-if="f.brand_primary" type="button" class="btn quiet sm" @click="f.brand_primary = ''">إرجاع للافتراضي</button>
            </div>
          </Field>
          <Field id="s-secondary" label="اللون التاني" :error="fieldErrors.brand_secondary">
            <div class="colour-row">
              <input id="s-secondary" type="color" :value="swatch(f.brand_secondary)" @input="f.brand_secondary = $event.target.value">
              <span class="mono muted">{{ f.brand_secondary || 'ألوان المنصة' }}</span>
              <button v-if="f.brand_secondary" type="button" class="btn quiet sm" @click="f.brand_secondary = ''">إرجاع للافتراضي</button>
            </div>
          </Field>
        </div>

        <div class="brand-preview" :style="pvStyle" aria-label="معاينة">
          <div class="pv-side">
            <div class="pv-nav">القسم المختار</div>
            <div class="pv-nav plain">قسم تاني</div>
          </div>
          <div class="pv-main"><span class="pv-btn">زرار</span></div>
        </div>

        <p v-if="preview?.adjusted?.primary" class="msg warn">
          اللون الأساسي فاتح أو غامق زيادة والكتابة فوقه مش هتتقري. بنستخدم درجة أقرب ({{ preview.accent }}) في الأزرار والعنصر المختار،
          واللون اللي اخترته بيفضل في الحدود والخلفيات الخفيفة.
        </p>
        <p v-if="preview?.adjusted?.secondary" class="msg warn">
          اللون التاني فاتح أو غامق زيادة والكتابة فوقه مش هتتقري. بنستخدم درجة أقرب ({{ preview.side }}) في القايمة والشريط.
        </p>

        <div class="field">
          <label for="s-logo">اللوجو</label>
          <div class="logo-box">
            <img v-if="logoUrl" :src="logoUrl" alt="اللوجو الحالي">
            <label class="btn sm" :class="{ disabled: logoBusy }" style="margin: 0">
              {{ logoUrl ? 'استبدال اللوجو' : 'رفع لوجو' }}
              <input id="s-logo" type="file" accept="image/png,image/jpeg,image/webp,image/svg+xml" class="sr-only" :disabled="logoBusy" @change="pickLogo">
            </label>
            <button v-if="logoUrl" type="button" class="btn quiet sm danger" :disabled="logoBusy" @click="dropLogo">شيل اللوجو</button>
          </div>
          <small class="muted">PNG أو JPEG أو WebP أو SVG، لحد 512 كيلوبايت و1000 بكسل. بيظهر في القايمة الجانبية والكاشير والإيصال.</small>
          <small v-if="logoError" class="field-error">{{ logoError }}</small>
        </div>
      </section>
      </fieldset>

      <div v-if="!session.readOnly" class="sticky-foot">
        <button class="btn primary" type="submit" :disabled="busy">{{ busy ? '...' : 'حفظ الإعدادات' }}</button>
      </div>
    </form>
  </div>
</template>
