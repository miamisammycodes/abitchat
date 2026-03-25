<script setup>
import { cn } from '@/lib/utils'
import { ref, provide, inject, computed } from 'vue'

const props = defineProps({
  modelValue: {
    type: String,
    default: '',
  },
})

const emit = defineEmits(['update:modelValue'])

const isOpen = ref(false)

provide('selectValue', computed(() => props.modelValue))
provide('selectOpen', isOpen)
provide('updateSelectValue', (value) => {
  emit('update:modelValue', value)
  isOpen.value = false
})
provide('toggleSelect', () => {
  isOpen.value = !isOpen.value
})
provide('closeSelect', () => {
  isOpen.value = false
})
</script>

<template>
  <div class="relative">
    <slot />
  </div>
</template>
