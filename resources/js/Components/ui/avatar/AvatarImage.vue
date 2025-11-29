<script setup>
import { cn } from '@/lib/utils'

defineProps({
  src: {
    type: String,
    default: '',
  },
  alt: {
    type: String,
    default: '',
  },
  class: {
    type: String,
    default: '',
  },
})
</script>

<template>
  <img :src="src" :alt="alt" :class="cn('aspect-square h-full w-full', $props.class)" />
</template>
