<script setup>
import { cn } from '@/lib/utils'

defineProps({
  class: {
    type: String,
    default: '',
  },
  modelValue: {
    type: String,
    default: '',
  },
  placeholder: {
    type: String,
    default: '',
  },
  disabled: {
    type: Boolean,
    default: false,
  },
  rows: {
    type: [String, Number],
    default: 3,
  },
})

defineEmits(['update:modelValue'])
</script>

<template>
  <textarea
    :value="modelValue"
    :placeholder="placeholder"
    :disabled="disabled"
    :rows="rows"
    :class="cn(
      'flex min-h-[60px] w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-sm placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-50',
      $props.class
    )"
    @input="$emit('update:modelValue', $event.target.value)"
  />
</template>
