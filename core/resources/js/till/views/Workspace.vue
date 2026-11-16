<script setup>
import { session, chooseBranch, chooseDevice } from '../../shared/session.js'

/**
 * Which counter this phone is. Asked once, then remembered.
 *
 * The till is chosen rather than assumed because its code is the prefix of
 * every invoice number it writes, and two phones sharing one till would number
 * their invoices identically the moment either of them goes offline.
 */

const emit = defineEmits(['done'])
</script>

<template>
  <div class="screen" style="justify-content: center">
    <div class="card" style="display: flex; flex-direction: column; gap: 14px">
      <div>
        <h1 style="margin: 0; font-size: 20px; font-weight: 700">{{ session.business?.name }}</h1>
        <p class="muted" style="margin: 2px 0 0">
          {{ session.user?.name }} · {{ session.user?.role_label }}
        </p>
      </div>

      <div class="field">
        <label for="branch">الفرع</label>
        <select
          id="branch"
          class="input"
          :value="session.branchId"
          @change="chooseBranch($event.target.value)"
        >
          <option v-for="branch in session.branches" :key="branch.id" :value="branch.id">
            {{ branch.name }}
          </option>
        </select>
      </div>

      <div class="field">
        <label for="device">الكاشير</label>
        <select
          id="device"
          class="input"
          :value="session.deviceId"
          @change="chooseDevice($event.target.value)"
        >
          <option v-for="device in session.devicesInBranch" :key="device.id" :value="device.id">
            {{ device.code }} — {{ device.name }}
          </option>
        </select>
        <p v-if="!session.devicesInBranch.length" class="msg warn" style="margin-top: 6px">
          مفيش كاشير مسجّل على الفرع ده. صاحب المحل بيضيفه من الإعدادات.
        </p>
      </div>

      <p v-if="session.device" class="muted">
        الفواتير هتترقّم <span class="mono">{{ session.device.code }}-000001</span> وهكذا.
      </p>

      <button class="btn primary big full" :disabled="!session.ready" @click="emit('done')">
        ابدأ البيع
      </button>
    </div>
  </div>
</template>
