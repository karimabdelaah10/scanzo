<script setup>
import { ref } from 'vue'

/**
 * A destructive action that asks once before it runs. The same two-step the
 * products screen uses for deleting a variant, shared by the screens that
 * delete a person or a till.
 */
defineProps({
    label: { type: String, default: 'مسح' },
    confirmLabel: { type: String, default: 'تأكيد المسح' },
})
const emit = defineEmits(['confirm'])

const asking = ref(false)

function yes() {
    asking.value = false
    emit('confirm')
}
</script>

<template>
  <template v-if="asking">
    <button class="btn sm danger" type="button" @click="yes">{{ confirmLabel }}</button>
    <button class="btn sm quiet" type="button" @click="asking = false">لأ</button>
  </template>
  <button v-else class="btn sm quiet danger" type="button" @click="asking = true">{{ label }}</button>
</template>
