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
        await signIn(email.value.trim(), password.value, '/till')
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
  <div class="screen" style="justify-content: center">
    <div class="card" style="display: flex; flex-direction: column; gap: 12px">
      <h1 style="margin: 0; font-size: 22px; font-weight: 700">Scanzo</h1>
      <p class="muted" style="margin: 0">سجّل دخولك لتبدأ البيع</p>

      <p v-if="error" class="msg bad">{{ error }}</p>

      <form @submit.prevent="submit" style="display: flex; flex-direction: column; gap: 12px">
        <div class="field">
          <label for="email">الإيميل</label>
          <input
            id="email"
            v-model="email"
            class="input"
            type="email"
            inputmode="email"
            autocomplete="username"
            required
          >
        </div>

        <div class="field">
          <label for="password">الباسورد</label>
          <input
            id="password"
            v-model="password"
            class="input"
            type="password"
            autocomplete="current-password"
            required
          >
        </div>

        <button class="btn primary big full" type="submit" :disabled="busy">
          {{ busy ? '...' : 'دخول' }}
        </button>
      </form>
    </div>
  </div>
</template>
