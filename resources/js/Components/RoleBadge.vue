<script setup>
/**
 * RoleBadge — displays a user's primary role as a coloured pill badge.
 *
 * Receives the `primaryRole` object emitted by HandleInertiaRequests::share()
 * under auth.user.primary_role: { value: string, label: string } | null
 */
defineProps({
  role: {
    type: Object,
    default: null,
  },
})

/** Map role value → Tailwind colour classes */
const colorMap = {
  super_admin: 'bg-violet-100 text-violet-800 dark:bg-violet-900/40 dark:text-violet-300',
  owner: 'bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-300',
  manager: 'bg-sky-100 text-sky-800 dark:bg-sky-900/40 dark:text-sky-300',
  agent: 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300',
}

const defaultColor = 'bg-muted text-muted-foreground'
</script>

<template>
  <span
    v-if="role"
    :class="[
      'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium',
      colorMap[role.value] ?? defaultColor,
    ]"
  >
    {{ role.label }}
  </span>
</template>
