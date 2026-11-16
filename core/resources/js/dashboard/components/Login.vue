<script setup>
import { ref } from 'vue'
import { signIn } from '../../shared/session.js'
import { ApiError } from '../../shared/api.js'

const email = ref('')
const password = ref('')
const error = ref('')
const busy = ref(false)

async function submit() {
    error.value = ''
    busy.value = true

    try {
        await signIn(email.value.trim(), password.value, '/dashboard')
    } catch (e) {
        error.value = e instanceof ApiError
            ? (e.fieldError('email') ?? e.message)
            : 'حصل خطأ غير متوقع'
    } finally {
        busy.value = false
    }
}
</script>

<template>
  <div class="card stack" style="max-width: 420px; margin: 12vh auto 0">
    <h1 style="margin: 0; font-size: 22px">Scanzo — لوحة النشاط</h1>
    <p v-if="error" class="msg bad">{{ error }}</p>
    <form class="stack" @submit.prevent="submit">
      <div class="field">
        <label for="email">الإيميل</label>
        <input id="email" v-model="email" class="input" type="email" autocomplete="username" required>
      </div>
      <div class="field">
        <label for="password">الباسورد</label>
        <input id="password" v-model="password" class="input" type="password" autocomplete="current-password" required>
      </div>
      <button class="btn primary big full" type="submit" :disabled="busy">{{ busy ? '...' : 'دخول' }}</button>
    </form>
  </div>
</template>
