<script setup>
import { ref } from 'vue'

/**
 * Type a value and press Enter (or a comma) to make it a chip. Leaving the box
 * with text still in it commits that too: losing "XL" because the user clicked
 * the next field would be a silent mistake.
 */
const props = defineProps({
    modelValue: { type: Array, required: true },
    placeholder: { type: String, default: '' },
    max: { type: Number, default: 30 },
    label: { type: String, default: '' },
})
const emit = defineEmits(['update:modelValue'])

const text = ref('')
const input = ref(null)

function add(raw) {
    const next = [...props.modelValue]

    for (const part of String(raw).split(/[,،\n]/)) {
        const value = part.trim().slice(0, 32)
        if (!value) continue
        if (next.length >= props.max) break
        if (next.some((v) => v.toLowerCase() === value.toLowerCase())) continue
        next.push(value)
    }

    emit('update:modelValue', next)
    text.value = ''
}

function remove(i) {
    emit('update:modelValue', props.modelValue.filter((_, idx) => idx !== i))
}

function key(e) {
    if (e.key === 'Enter' || e.key === ',' || e.key === '،') {
        // Enter must not submit the form around this box.
        e.preventDefault()
        add(text.value)
    } else if (e.key === 'Backspace' && text.value === '' && props.modelValue.length) {
        remove(props.modelValue.length - 1)
    }
}
</script>

<template>
  <div class="chips" @click="input?.focus()">
    <span v-for="(v, i) in modelValue" :key="v" class="pill">
      {{ v }}
      <button type="button" :aria-label="`شيل ${v}`" @click.stop="remove(i)">×</button>
    </span>
    <input
      ref="input"
      v-model="text"
      :placeholder="modelValue.length ? '' : placeholder"
      :aria-label="label"
      @keydown="key"
      @blur="add(text)"
    >
  </div>
</template>
