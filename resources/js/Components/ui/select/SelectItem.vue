<script setup>
import { cn } from '@/lib/utils'
import { inject, computed } from 'vue'
import { Check } from 'lucide-vue-next'

const props = defineProps({
  value: {
    type: String,
    required: true,
  },
  class: {
    type: String,
    default: '',
  },
})

const selectValue = inject('selectValue')
const updateSelectValue = inject('updateSelectValue')

const isSelected = computed(() => selectValue.value === props.value)

const select = () => {
  updateSelectValue(props.value)
}
</script>

<template>
  <div
    :class="cn(
      'relative flex w-full cursor-pointer select-none items-center rounded-sm py-1.5 pl-2 pr-8 text-sm outline-none hover:bg-accent hover:text-accent-foreground focus:bg-accent focus:text-accent-foreground',
      isSelected && 'bg-accent',
      $props.class
    )"
    @click="select"
  >
    <span class="absolute right-2 flex h-3.5 w-3.5 items-center justify-center">
      <Check v-if="isSelected" class="h-4 w-4" />
    </span>
    <slot />
  </div>
</template>
