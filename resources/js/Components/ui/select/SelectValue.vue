<script setup>
import { inject, computed } from 'vue'

const props = defineProps({
  placeholder: {
    type: String,
    default: 'Select...',
  },
})

const selectValue = inject('selectValue')

const displayValue = computed(() => {
  return selectValue.value || props.placeholder
})
</script>

<template>
  <span :class="{ 'text-muted-foreground': !selectValue }">
    {{ displayValue }}
  </span>
</template>
