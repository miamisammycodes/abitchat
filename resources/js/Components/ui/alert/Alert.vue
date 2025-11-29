<script setup>
import { cn } from '@/lib/utils'
import { cva } from 'class-variance-authority'

const alertVariants = cva(
  'relative w-full rounded-lg border px-4 py-3 text-sm [&>svg+div]:translate-y-[-3px] [&>svg]:absolute [&>svg]:left-4 [&>svg]:top-4 [&>svg]:text-foreground [&>svg~*]:pl-7',
  {
    variants: {
      variant: {
        default: 'bg-background text-foreground',
        destructive:
          'border-destructive/50 text-destructive dark:border-destructive [&>svg]:text-destructive',
        warning:
          'border-yellow-200 bg-yellow-50 text-yellow-800 [&>svg]:text-yellow-600',
      },
    },
    defaultVariants: {
      variant: 'default',
    },
  }
)

defineProps({
  variant: {
    type: String,
    default: 'default',
  },
  class: {
    type: String,
    default: '',
  },
})
</script>

<template>
  <div
    role="alert"
    :class="cn(alertVariants({ variant }), $props.class)"
  >
    <slot />
  </div>
</template>
