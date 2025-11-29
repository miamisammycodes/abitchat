<script setup>
import { cn } from '@/lib/utils'

defineProps({
  class: {
    type: String,
    default: '',
  },
})
</script>

<template>
  <span :class="cn('relative flex h-10 w-10 shrink-0 overflow-hidden rounded-full', $props.class)">
    <slot />
  </span>
</template>
