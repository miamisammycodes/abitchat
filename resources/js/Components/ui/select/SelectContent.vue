<script setup>
import { cn } from '@/lib/utils'
import { inject, onMounted, onUnmounted } from 'vue'

defineProps({
  class: {
    type: String,
    default: '',
  },
})

const isOpen = inject('selectOpen')
const closeSelect = inject('closeSelect')

const handleClickOutside = (e) => {
  if (!e.target.closest('.relative')) {
    closeSelect()
  }
}

onMounted(() => {
  document.addEventListener('click', handleClickOutside)
})

onUnmounted(() => {
  document.removeEventListener('click', handleClickOutside)
})
</script>

<template>
  <div
    v-if="isOpen"
    :class="cn(
      'absolute z-50 mt-1 max-h-60 w-full overflow-auto rounded-md border bg-popover p-1 text-popover-foreground shadow-md',
      $props.class
    )"
  >
    <slot />
  </div>
</template>
