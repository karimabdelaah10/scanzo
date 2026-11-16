<script setup>
/**
 * A labelled form field with its error underneath. The input goes in the slot
 * and carries the same `id`, so the label focuses it.
 */
defineProps({
    label: { type: String, required: true },
    id: { type: String, required: true },
    error: { type: String, default: '' },
    hint: { type: String, default: '' },
})
</script>

<template>
  <div class="field">
    <label :for="id">{{ label }}</label>
    <slot />
    <small v-if="hint && !error" class="muted">{{ hint }}</small>
    <small v-if="error" class="field-error">{{ error }}</small>
  </div>
</template>
